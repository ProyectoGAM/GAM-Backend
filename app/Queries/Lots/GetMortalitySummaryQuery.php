<?php

namespace App\Queries\Lots;

use App\Models\Lots\MortalityRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class GetMortalitySummaryQuery
{
    public function __construct(private GetMortalityReferencePopulationQuery $population) {}

    /** @return array{up_id: int|null, desde: string, hasta: string, zona_horaria: string, bajas_registradas: int, poblacion_referencia: int|null, tasa_porcentual: float|null, estado_tasa: string} */
    public function execute(string $from, string $to, ?int $unitId): array
    {
        $timezone = config('lots.timezone');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay();
        $mortality = MortalityRecord::query()->forReportingPeriod($from, $to, $unitId)
            ->where('status', 'recorded')->selectRaw('COALESCE(SUM(quantity), 0)')->toBase();
        $summary = DB::query()->selectSub($mortality, 'deaths')
            ->selectSub($this->population->forPeriod($start, $end, $unitId), 'population')->first();
        $deaths = (int) $summary->deaths;
        $population = $summary->population === null ? null : (int) $summary->population;

        return [
            'up_id' => $unitId,
            'desde' => $from,
            'hasta' => $to,
            'zona_horaria' => $timezone,
            'bajas_registradas' => $deaths,
            'poblacion_referencia' => $population,
            'tasa_porcentual' => $population > 0 ? round($deaths / $population * 100, 2) : null,
            'estado_tasa' => match (true) {
                $population === null => 'historial_indeterminado',
                $population === 0 => 'sin_poblacion',
                $deaths > $population => 'bajas_superan_poblacion',
                default => 'calculada',
            },
        ];
    }
}
