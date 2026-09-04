<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\EggCollection;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Shared\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class GetEggProductionMetricsQuery
{
    public function __construct(private Clock $clock) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(array $filters): array
    {
        $timezone = config('lots.timezone');
        $until = isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to'], $timezone)->startOfDay() : $this->clock->now()->setTimezone($timezone)->startOfDay();
        $from = isset($filters['date_from']) ? CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay() : $until->subDays(29);
        $days = (int) $from->diffInDays($until, false) + 1;
        if ($days < 1 || $days > 366) {
            throw new LotsConflict('El período de métricas debe comprender entre 1 y 366 días.');
        }

        $query = EggCollection::query()
            ->where('status', 'recorded')
            ->where('occurred_at', '>=', $from->toIso8601String())
            ->where('occurred_at', '<', $until->addDay()->toIso8601String());
        $this->applyFilters($query, $filters);
        $rows = $query->selectRaw('(occurred_at AT TIME ZONE ?)::date AS period, SUM(quantity) AS total', [$timezone])->groupBy('period')->orderBy('period')->toBase()->get();
        $periods = [];
        foreach ($rows as $row) {
            $date = (string) $row->period;
            $periods[$date] = ['fecha' => $date, 'cantidad' => (int) $row->total, 'huevos_recolectados' => (int) $row->total];
        }
        ksort($periods);
        $weekly = $this->group($periods, 'week');
        $monthly = $this->group($periods, 'month');
        $total = array_sum(array_column($periods, 'cantidad'));

        return [
            'fecha_desde' => $from->toDateString(), 'fecha_hasta' => $until->toDateString(), 'zona_horaria' => $timezone,
            'huevos_totales' => $total, 'huevos_recolectados' => $total, 'promedio_diario' => round($total / $days, 2),
            'por_dia' => array_values($periods), 'por_semana' => array_values($weekly), 'por_mes' => array_values($monthly),
        ];
    }

    /** @param Builder<EggCollection> $query @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['flock_id'])) {
            $query->whereHas('flock', fn (Builder $flock): Builder => $flock->where('public_id', $filters['flock_id']));
        }
        if (isset($filters['poultry_house_id'])) {
            $query->where('poultry_house_id', $filters['poultry_house_id']);
        }
        if (isset($filters['production_unit_id'])) {
            $query->where('production_unit_id', $filters['production_unit_id']);
        }
    }

    /** @param array<string, array<string, mixed>> $daily @return array<string, array<string, mixed>> */
    private function group(array $daily, string $unit): array
    {
        $result = [];
        foreach ($daily as $row) {
            $date = CarbonImmutable::parse($row['fecha'], config('lots.timezone'));
            $key = $unit === 'week' ? $date->startOfWeek()->toDateString() : $date->startOfMonth()->toDateString();
            $result[$key] ??= [$unit === 'week' ? 'inicio_semana' : 'inicio_mes' => $key, 'cantidad' => 0, 'huevos_recolectados' => 0];
            $result[$key]['cantidad'] += $row['cantidad'];
            $result[$key]['huevos_recolectados'] += $row['huevos_recolectados'];
        }
        ksort($result);

        return $result;
    }
}
