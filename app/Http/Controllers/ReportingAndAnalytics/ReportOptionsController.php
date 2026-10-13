<?php

namespace App\Http\Controllers\ReportingAndAnalytics;

use App\Http\Requests\ReportingAndAnalytics\ListReportOptionsRequest;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use App\Services\ReportingAndAnalytics\SqlReportSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final readonly class ReportOptionsController
{
    public function index(ListReportOptionsRequest $request, string $source, ReportSourceRegistry $registry): JsonResponse
    {
        $report = $registry->get($source);
        $field = $request->validated('field');
        $definition = $report->definition()->filters[$field];
        if (isset($definition['options']) || $definition['type'] === 'boolean') {
            $values = $definition['options'] ?? [true, false];

            return response()->json(['data' => array_map(fn (mixed $value): array => ['value' => $value, 'label' => is_bool($value) ? ($value ? 'Sí' : 'No') : (string) $value], $values),
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => count($values)]]);
        }
        if ($report instanceof SqlReportSource) {
            return response()->json($report->options($field, (string) $request->input('search', ''), (int) $request->input('page', 1)));
        }
        $tables = ['product_id' => 'products', 'stock_location_id' => 'stock_locations', 'production_unit_id' => 'production_units', 'supplier_id' => 'suppliers'];
        if (! isset($tables[$field])) {
            return response()->json(['data' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }
        $query = DB::table($tables[$field])->select(['id as value', 'name as label']);
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->validated('search').'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(50, ['*'], 'page', (int) $request->input('page', 1));

        return response()->json(['data' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }
}
