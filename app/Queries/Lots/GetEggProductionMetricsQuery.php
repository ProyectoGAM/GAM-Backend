<?php

namespace App\Queries\Lots;

use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\EggCollection;
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
            $periods[$date] = ['date' => $date, 'quantity' => (int) $row->total, 'collected_eggs' => (int) $row->total];
        }
        ksort($periods);
        $weekly = $this->group($periods, 'week');
        $monthly = $this->group($periods, 'month');
        $total = array_sum(array_column($periods, 'quantity'));

        return [
            'date_from' => $from->toDateString(), 'date_to' => $until->toDateString(), 'timezone' => $timezone,
            'total_eggs' => $total, 'collected_eggs' => $total, 'daily_average' => round($total / $days, 2),
            'by_day' => array_values($periods), 'by_week' => array_values($weekly), 'by_month' => array_values($monthly),
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
            $date = CarbonImmutable::parse($row['date'], config('lots.timezone'));
            $key = $unit === 'week' ? $date->startOfWeek()->toDateString() : $date->startOfMonth()->toDateString();
            $result[$key] ??= [$unit === 'week' ? 'week_start' : 'month_start' => $key, 'quantity' => 0, 'collected_eggs' => 0];
            $result[$key]['quantity'] += $row['quantity'];
            $result[$key]['collected_eggs'] += $row['collected_eggs'];
        }
        ksort($result);

        return $result;
    }
}
