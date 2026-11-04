<?php

namespace App\Http\Middleware;

use App\Models\Inventory\StockLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceProductionUnitAccess
{
    /**
     * Aplica las restricciones de UP y las exclusiones de recursos globales.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->usesDevicePin()) {
            return $next($request);
        }
        $route = $request->route();
        $controller = $route?->getControllerClass();
        $action = $route?->getActionMethod();
        $globalControllers = ['SupplierController', 'SupplierStatusController', 'ProductController', 'ProductStatusController',
            'MedicineController', 'VaccineController', 'VaccineStatusController', 'BreedController', 'PlanTemplateController',
            'FeedRecipeController', 'EggPresentationController', 'DepartmentController', 'LocalityController'];
        $short = class_basename($controller ?? '');
        abort_if(in_array($short, ['UserManagementController', 'RoleController', 'AdminController', 'CustomerController', 'CustomerAccountController'], true), 403);
        abort_if($short === 'VehicleController' && ! in_array($action, ['index', 'recordFuel'], true), 403);
        abort_if(in_array($short, $globalControllers, true) && ! $request->isMethodSafe()
            && ! ($short === 'FeedRecipeController' && $action === 'calculate'), 403);
        abort_if($short === 'ProductionUnitController' && in_array($action, ['store', 'validateLocation'], true), 403);
        abort_if($short === 'SharedDeviceController' && in_array($action, ['generateCode', 'managedIndex', 'revokeDevice'], true), 403);
        abort_if($short === 'ReportExportController' && $action === 'share', 403);
        abort_if($short === 'WeighingController' && $action === 'updateSettings', 403);
        abort_if($short === 'FeedStockController' && $action === 'store', 403);
        abort_if($short === 'ManagementExecutionController' && in_array($action, ['showMedicineStock', 'adjustMedicineStock'], true), 403);
        $this->validateUnitFields($request->all(), (int) $request->user()->production_unit_id);

        return $next($request);
    }

    /** Impide seleccionar otra UP incluso en filtros y estructuras anidadas. @param array<string|int, mixed> $input */
    private function validateUnitFields(array $input, int $unitId): void
    {
        foreach ($input as $key => $value) {
            if (in_array($key, ['stock_location_id', 'from_stock_location_id', 'to_stock_location_id', 'source_stock_location_id', 'destination_stock_location_id'], true) && $value !== null) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    abort_unless(is_scalar($id) && StockLocation::query()->whereKey($id)->exists(), 404);
                }
            }
            if (in_array($key, ['up_id', 'production_unit_id', 'return_production_unit_id', 'source_production_unit_id', 'destination_production_unit_id'], true) && $value !== null) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    abort_unless(is_scalar($id) && (string) $id === (string) $unitId, 404);
                }
            } elseif (is_array($value)) {
                $this->validateUnitFields($value, $unitId);
            }
        }
    }
}
