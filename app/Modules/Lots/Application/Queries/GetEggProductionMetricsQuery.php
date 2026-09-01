<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Inventory\InventoryMovement;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Shared\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class GetEggProductionMetricsQuery
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters): array
    {
        $timezone = config('lots.timezone');
        $until = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'], $timezone)->startOfDay()
            : $this->clock->now()->setTimezone($timezone)->startOfDay();
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay()
            : $until->subDays(29);
        $days = (int) $from->diffInDays($until, false) + 1;
        if ($days < 1 || $days > 366) {
            throw new LotsConflict('El período de métricas debe comprender entre 1 y 366 días.');
        }

        $collections = EggCollection::query()
            ->where('status', 'recorded')
            ->where('occurred_at', '>=', $from->toIso8601String())
            ->where('occurred_at', '<', $until->addDay()->toIso8601String());
        $this->applyFilters($collections, $filters);
        $collectionRows = (clone $collections)
            ->selectRaw('(occurred_at AT TIME ZONE ?)::date AS period, SUM(collected_quantity) AS gross, SUM(discarded_quantity) AS initial_discard, SUM(collected_quantity - discarded_quantity) AS usable', [$timezone])
            ->groupBy('period')
            ->orderBy('period')
            ->toBase()
            ->get();

        $losses = InventoryMovement::query()
            ->join('inventory_movement_lines', 'inventory_movement_lines.inventory_movement_id', '=', 'inventory_movements.id')
            ->join('egg_collections', function ($join): void {
                $join->on('egg_collections.public_id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', '=', 'egg_collection');
            })
            ->where('inventory_movements.type', 'loss')
            ->where('egg_collections.status', 'recorded')
            ->where('inventory_movements.occurred_at', '>=', $from->toIso8601String())
            ->where('inventory_movements.occurred_at', '<', $until->addDay()->toIso8601String())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('inventory_movements AS reversals')
                    ->whereColumn('reversals.reverses_movement_id', 'inventory_movements.id');
            });
        if (isset($filters['flock_id'])) {
            $losses->whereIn('egg_collections.flock_id', Flock::query()->where('public_id', $filters['flock_id'])->select('id'));
        }
        if (isset($filters['poultry_house_id'])) {
            $losses->where('egg_collections.poultry_house_id', $filters['poultry_house_id']);
        }
        $lossRows = (clone $losses)
            ->selectRaw('(inventory_movements.occurred_at AT TIME ZONE ?)::date AS period, SUM(ABS(inventory_movement_lines.on_hand_delta)) AS post_loss', [$timezone])
            ->groupBy('period')
            ->orderBy('period')
            ->toBase()
            ->get();

        $gross = (int) (clone $collections)->sum('collected_quantity');
        $initialDiscard = (int) (clone $collections)->sum('discarded_quantity');
        $postLoss = (int) (clone $losses)->selectRaw('COALESCE(SUM(ABS(inventory_movement_lines.on_hand_delta)), 0) AS total_loss')->value('total_loss');
        $periods = $this->mergeDaily($collectionRows, $lossRows);
        $weekly = $this->groupPeriods($periods, 'week');
        $monthly = $this->groupPeriods($periods, 'month');
        $usable = $gross - $initialDiscard;
        $net = $usable - $postLoss;

        return [
            'fecha_desde' => $from->toDateString(),
            'fecha_hasta' => $until->toDateString(),
            'zona_horaria' => $timezone,
            'huevos_totales' => $gross,
            'huevos_recolectados' => $gross,
            'huevos_descartados_iniciales' => $initialDiscard,
            'huevos_utilizables' => $usable,
            'huevos_perdidos_posteriores' => $postLoss,
            'huevos_netos' => $net,
            'promedio_diario' => round($net / $days, 2),
            'promedio_diario_neto' => round($net / $days, 2),
            'por_dia' => array_values($periods),
            'por_semana' => array_values($weekly),
            'por_mes' => array_values($monthly),
        ];
    }

    /** @param Builder<EggCollection> $query @param array<string, mixed> $filters */
    private function applyFilters($query, array $filters): void
    {
        if (isset($filters['flock_id'])) {
            $query->whereHas('flock', fn ($flock) => $flock->where('public_id', $filters['flock_id']));
        }
        if (isset($filters['poultry_house_id'])) {
            $query->where('poultry_house_id', $filters['poultry_house_id']);
        }
    }

    /** @param Collection $collections @param Collection $losses @return array<string, array<string, mixed>> */
    private function mergeDaily($collections, $losses): array
    {
        $periods = [];
        foreach ($collections as $row) {
            $date = (string) $row->period;
            $periods[$date] = [
                'fecha' => $date,
                'huevos_recolectados' => (int) $row->gross,
                'huevos_descartados_iniciales' => (int) $row->initial_discard,
                'huevos_perdidos_posteriores' => 0,
                'huevos_netos' => (int) $row->usable,
            ];
        }
        foreach ($losses as $row) {
            $date = (string) $row->period;
            $periods[$date] ??= [
                'fecha' => $date,
                'huevos_recolectados' => 0,
                'huevos_descartados_iniciales' => 0,
                'huevos_perdidos_posteriores' => 0,
                'huevos_netos' => 0,
            ];
            $periods[$date]['huevos_perdidos_posteriores'] += (int) $row->post_loss;
            $periods[$date]['huevos_netos'] -= (int) $row->post_loss;
        }
        ksort($periods);
        foreach ($periods as &$period) {
            $period['cantidad'] = $period['huevos_recolectados'];
        }

        return $periods;
    }

    /** @param array<string, array<string, mixed>> $daily @return array<string, array<string, mixed>> */
    private function groupPeriods(array $daily, string $unit): array
    {
        $result = [];
        $timezone = config('lots.timezone');
        foreach ($daily as $row) {
            $date = CarbonImmutable::parse($row['fecha'], $timezone);
            $key = $unit === 'week' ? $date->startOfWeek()->toDateString() : $date->startOfMonth()->toDateString();
            $result[$key] ??= [
                $unit === 'week' ? 'inicio_semana' : 'inicio_mes' => $key,
                'huevos_recolectados' => 0,
                'huevos_descartados_iniciales' => 0,
                'huevos_perdidos_posteriores' => 0,
                'huevos_netos' => 0,
            ];
            foreach (['huevos_recolectados', 'huevos_descartados_iniciales', 'huevos_perdidos_posteriores', 'huevos_netos'] as $field) {
                $result[$key][$field] += $row[$field];
            }
            $result[$key]['cantidad'] = $result[$key]['huevos_recolectados'];
        }
        ksort($result);

        return $result;
    }
}
