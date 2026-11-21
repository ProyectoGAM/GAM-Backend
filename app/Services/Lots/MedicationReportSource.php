<?php

namespace App\Services\Lots;

use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class MedicationReportSource extends SqlReportSource
{
    public function __construct(private HistoricalFlockHousing $housing) {}

    public function definition(): ReportSourceDefinition
    {
        return $this->describe('lots.medications', 'Medicación', 'Aplicaciones por lote con medicamento y responsable históricos. Una cantidad desconocida permanece vacía.', 'can_view_management_plans', [
            'date' => ['label' => 'Fecha', 'type' => 'datetime'],
            'flock_id' => ['label' => 'Lote', 'type' => 'string'], 'flock' => ['label' => 'Lote', 'type' => 'string'],
            'production_unit_id' => ['label' => 'Unidad productiva', 'type' => 'integer'], 'production_unit' => ['label' => 'Unidad productiva', 'type' => 'string'],
            'poultry_house_id' => ['label' => 'Galpón', 'type' => 'integer'], 'poultry_house' => ['label' => 'Galpón', 'type' => 'string'],
            'medicine_id' => ['label' => 'Medicamento', 'type' => 'string'], 'medicine' => ['label' => 'Medicamento', 'type' => 'string'],
            'quantity' => ['label' => 'Cantidad registrada', 'type' => 'integer'],
            'responsible' => ['label' => 'Responsable', 'type' => 'string'],
            'reason' => ['label' => 'Motivo', 'type' => 'string'], 'notes' => ['label' => 'Observaciones', 'type' => 'string'],
        ], ['application_count' => ['label' => 'Aplicaciones', 'type' => 'count']], extraFilters: [
            'responsible' => ['label' => 'Responsable', 'type' => 'string', 'operators' => ['eq', 'in'], 'options_source' => 'responsible'],
        ], standardQueries: [['label' => 'Aplicaciones por mes y lote', 'configuration' => ['groupings' => ['month', 'flock', 'medicine'], 'metrics' => ['application_count']]]]);
    }

    protected function dimensionKeys(): array
    {
        return [...parent::dimensionKeys(), 'medicine' => 'medicine_id'];
    }

    protected function baseQuery(): Builder
    {
        $records = DB::table('medicine_applications')->selectRaw('*, occurred_at as date');

        return $this->housing->join(DB::query()->fromSub($records, 'r')->join('flocks as f', 'f.id', '=', 'r.flock_id'))
            ->whereRaw('(r.production_unit_id IS NULL OR r.production_unit_id = u.id)')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('management_execution_corrections as correction')
                ->where('correction.original_type', 'medication')->whereColumn('correction.original_public_id', 'r.public_id'))
            ->selectRaw('r.id as row_key, r.date, f.public_id as flock_id, f.code as flock, u.id as production_unit_id, u.name as production_unit, h.id as poultry_house_id, h.name as poultry_house,
                r.medicine_public_id_snapshot as medicine_id, r.medicine_name_snapshot as medicine, r.quantity, r.responsible_name_snapshot as responsible, r.reason, r.notes');
    }

    protected function metricExpressions(): array
    {
        return ['application_count' => 'COUNT(*)'];
    }
}
