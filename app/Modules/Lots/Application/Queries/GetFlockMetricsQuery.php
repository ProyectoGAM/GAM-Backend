<?php

namespace App\Modules\Lots\Application\Queries;

use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Shared\Clock;
use Carbon\CarbonImmutable;

final readonly class GetFlockMetricsQuery
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(Flock $flock, array $filters): array
    {
        $timezone = config('lots.timezone');
        $until = isset($filters['date_to']) ? CarbonImmutable::parse($filters['date_to'], $timezone)->startOfDay() : $this->clock->now()->setTimezone($timezone)->startOfDay();
        $from = isset($filters['date_from']) ? CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay() : $until->subDays(29);
        $days = (int) $from->diffInDays($until, false) + 1;
        if ($days < 1 || $days > 366) {
            throw new LotsConflict('El período de métricas debe comprender entre 1 y 366 días.');
        }
        $query = EggCollection::query()->where('flock_id', $flock->id)->where('status', 'recorded')
            ->where('occurred_at', '>=', $from->toIso8601String())->where('occurred_at', '<', $until->addDay()->toIso8601String());
        $daily = (clone $query)->selectRaw('(occurred_at AT TIME ZONE ?)::date AS day, SUM(quantity) AS total', [$timezone])->groupBy('day')->orderBy('day')->toBase()->get();
        $weekly = (clone $query)->selectRaw("date_trunc('week', occurred_at AT TIME ZONE ?)::date AS week, SUM(quantity) AS total", [$timezone])->groupBy('week')->orderBy('week')->toBase()->get();
        $total = (int) (clone $query)->sum('quantity');

        return [
            'lote_id' => $flock->public_id, 'fecha_desde' => $from->toDateString(), 'fecha_hasta' => $until->toDateString(),
            'zona_horaria' => $timezone, 'huevos_totales' => $total, 'promedio_diario' => round($total / $days, 2),
            'por_dia' => $daily->map(fn ($row): array => ['fecha' => $row->day, 'cantidad' => (int) $row->total])->all(),
            'por_semana' => $weekly->map(fn ($row): array => ['inicio_semana' => $row->week, 'cantidad' => (int) $row->total])->all(),
        ];
    }
}
