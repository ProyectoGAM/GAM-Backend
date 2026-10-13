<?php

namespace App\Services\ManagementPlans;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\Lots\HistoricalFlockHousing;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VaccinationReportSource extends SqlReportSource
{
    public function __construct(private HistoricalFlockHousing $housing) {}

    public function definition(): ReportSourceDefinition
    {
        return $this->describe('management.vaccinations', 'Vacunación', 'Aplicaciones válidas, vacuna y partida registrada. Los datos históricos desconocidos permanecen vacíos.', 'management-plans.view', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'], 'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'],
            'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'], 'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'],
            'poultry_house' => ['label' => 'Galpón', 'type' => 'string'], 'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'vaccine' => ['label' => 'Vacuna', 'type' => 'string'], 'vaccine_batch' => ['label' => 'Lote de vacuna', 'type' => 'string'],
            'notes' => ['label' => 'Comentarios', 'type' => 'string'],
        ], ['application_count' => ['label' => 'Aplicaciones', 'type' => 'count']]);
    }

    protected function baseQuery(): Builder
    {
        $records = DB::table('vaccination_applications as v')->selectRaw('v.*, v.occurred_at as date');

        return $this->housing->join(DB::query()->fromSub($records, 'r')->join('flocks as f', 'f.id', '=', 'r.flock_id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('management_execution_corrections as c')->where('c.original_type', 'vaccination')->whereColumn('c.original_public_id', 'r.public_id'))
            ->selectRaw("r.id as row_key, r.date, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house, f.public_id as flock_id, f.code as flock, r.product_snapshot->>'name' as vaccine, r.vaccine_batch, r.notes");
    }

    protected function metricExpressions(): array
    {
        return ['application_count' => 'COUNT(*)'];
    }
}
