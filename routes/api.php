<?php

use App\Http\Controllers\AuditAndTraceability\AuditEntryController;
use App\Http\Controllers\FarmStructure\MaintenanceController;
use App\Http\Controllers\FarmStructure\PoultryHouseController;
use App\Http\Controllers\FarmStructure\PoultryHouseStatusController;
use App\Http\Controllers\FarmStructure\ProductionUnitController;
use App\Http\Controllers\FarmStructure\ProductionUnitStatusController;
use App\Http\Controllers\Geography\DepartmentController;
use App\Http\Controllers\Geography\LocalityController;
use App\Http\Controllers\IdentityAndAccess\AdminController;
use App\Http\Controllers\IdentityAndAccess\AuthController;
use App\Http\Controllers\IdentityAndAccess\SharedDeviceController;
use App\Http\Controllers\IdentityAndAccess\UserManagementController;
use App\Http\Controllers\Inventory\EggStockController;
use App\Http\Controllers\Inventory\InventoryMovementController;
use App\Http\Controllers\Inventory\InventoryReadController;
use App\Http\Controllers\Inventory\StockLocationController;
use App\Http\Controllers\Inventory\StockLocationStatusController;
use App\Http\Controllers\Lots\BreedController;
use App\Http\Controllers\Lots\EggCollectionController;
use App\Http\Controllers\Lots\FlockController;
use App\Http\Controllers\Lots\FlockRedistributionController;
use App\Http\Controllers\Lots\FlockStatusController;
use App\Http\Controllers\Lots\MortalityCategoryController;
use App\Http\Controllers\Lots\MortalityController;
use App\Http\Controllers\ReferenceData\ReferenceOptionsController;
use App\Http\Controllers\ReportingAndAnalytics\ReportExportController;
use App\Http\Controllers\ReportingAndAnalytics\ReportPresetController;
use App\Http\Controllers\ReportingAndAnalytics\ReportSourceController;
use App\Http\Controllers\SuppliersAndCatalogs\MedicineController;
use App\Http\Controllers\SuppliersAndCatalogs\ProductController;
use App\Http\Controllers\SuppliersAndCatalogs\ProductStatusController;
use App\Http\Controllers\SuppliersAndCatalogs\SupplierController;
use App\Http\Controllers\SuppliersAndCatalogs\SupplierStatusController;
use App\Http\Controllers\SuppliersAndCatalogs\VaccineController;
use App\Http\Controllers\SuppliersAndCatalogs\VaccineStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('auth')->name('auth.')->middleware(['throttle:auth', 'throttle:auth-account'])->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('login');
    });

    Route::post('/shared-devices/pairing', [SharedDeviceController::class, 'redeemCode'])
        ->middleware('throttle:pairing')
        ->name('shared-devices.pair');

    Route::get('/shared-device', [SharedDeviceController::class, 'status'])
        ->middleware('shared.device:native')
        ->name('shared-device.status');
    Route::get('/shared-device/users', [SharedDeviceController::class, 'users'])
        ->middleware(['shared.device:native', 'throttle:shared-device'])
        ->name('shared-device.users');
    Route::post('/shared-device/login-pin', [SharedDeviceController::class, 'loginPin'])
        ->middleware(['shared.device:native', 'throttle:pin'])
        ->name('shared-device.pin-login');
    Route::post('/shared-device/finalize', [SharedDeviceController::class, 'finalize'])
        ->middleware('shared.device:native')
        ->name('shared-device.finalize');
    Route::delete('/shared-device/pairing', [SharedDeviceController::class, 'revokeLocal'])
        ->middleware('shared.device:native')
        ->name('shared-device.local-revoke');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::name('lots.')->group(function (): void {
            Route::get('/flocks', [FlockController::class, 'index'])->name('index');
            Route::post('/flocks', [FlockController::class, 'store'])->name('store');
            Route::get('/flocks/{flock}', [FlockController::class, 'show'])->name('show');
            Route::patch('/flocks/{flock}', [FlockController::class, 'update'])->name('update');
            Route::patch('/flocks/{flock}/status', [FlockStatusController::class, 'update'])->name('status');
            Route::post('/flocks/{flock}/finalization', [FlockStatusController::class, 'finalize'])->name('finalize');
            Route::post('/flocks/{flock}/redistributions', [FlockRedistributionController::class, 'store'])->name('redistribute');
            Route::post('/redistributions/{redistribution}/reversals', [FlockRedistributionController::class, 'reverse'])->name('redistributions.reverse');
            Route::get('/flocks/{flock}/history', [FlockController::class, 'history'])->name('history');
            Route::get('/poultry-houses/{poultryHouse}/flocks', [FlockController::class, 'index'])->name('by-house');
            Route::get('/breeds', [BreedController::class, 'index'])->name('breeds.index');
            Route::post('/breeds', [BreedController::class, 'store'])->name('breeds.store');
            Route::patch('/breeds/{breed}', [BreedController::class, 'update'])->name('breeds.update');
            Route::get('/mortality-categories', [MortalityCategoryController::class, 'index'])->name('mortality-categories.index');
            Route::post('/mortality-categories', [MortalityCategoryController::class, 'store'])->name('mortality-categories.store');
            Route::patch('/mortality-categories/{mortalityCategory}', [MortalityCategoryController::class, 'update'])->name('mortality-categories.update');
            Route::get('/mortalities', [MortalityController::class, 'index'])->name('mortality.index');
            Route::get('/mortalities/{mortality}', [MortalityController::class, 'show'])->name('mortality.show');
            Route::get('/flocks/{flock}/mortalities', [MortalityController::class, 'byFlock'])->name('mortality.by-flock');
            Route::post('/flocks/{flock}/mortalities', [MortalityController::class, 'store'])->name('mortality.store');
            Route::patch('/mortalities/{mortality}', [MortalityController::class, 'update'])->name('mortality.update');
            Route::post('/mortalities/{mortality}/cancellation', [MortalityController::class, 'cancel'])->name('mortality.cancel');
            Route::get('/collections', [EggCollectionController::class, 'indexAll'])->name('collections.index-all');
            Route::get('/collections/metrics', [EggCollectionController::class, 'metricsAll'])->name('collections.metrics');
            Route::get('/flocks/{flock}/collections', [EggCollectionController::class, 'index'])->name('collections.index');
            Route::get('/collections/{collection}', [EggCollectionController::class, 'show'])->name('collections.show');
            Route::post('/flocks/{flock}/collections', [EggCollectionController::class, 'store'])->name('collections.store');
            Route::patch('/collections/{collection}', [EggCollectionController::class, 'update'])->name('collections.update');
            Route::post('/collections/{collection}/cancellation', [EggCollectionController::class, 'cancel'])->name('collections.cancel');
            Route::get('/flocks/{flock}/metrics', [EggCollectionController::class, 'metrics'])->name('metrics');
        });

        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::get('/sessions', [AuthController::class, 'sessions'])->name('auth.sessions');
        Route::delete('/sessions/{session}', [AuthController::class, 'revokeSession'])->name('auth.sessions.revoke');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/confirm-password', [AuthController::class, 'confirmPassword'])->name('auth.confirm-password');

        Route::get('/users', [UserManagementController::class, 'index'])
            ->middleware('permission:identity.users.manage')
            ->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])
            ->middleware('permission:identity.users.manage')
            ->name('users.store');
        Route::patch('/users/{user}/status', [UserManagementController::class, 'status'])
            ->middleware('permission:identity.users.manage')
            ->withTrashed()
            ->name('users.status');
        Route::put('/users/{user}/roles', [UserManagementController::class, 'roles'])
            ->middleware('permission:identity.users.manage')
            ->name('users.roles');
        Route::put('/users/{user}/pin', [UserManagementController::class, 'setPin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.update');
        Route::delete('/users/{user}/pin', [UserManagementController::class, 'deletePin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.destroy');
        Route::post('/users/{user}/pin/unlock', [UserManagementController::class, 'unlockPin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.unlock');
        Route::put('/users/{user}/password', [UserManagementController::class, 'password'])
            ->middleware('permission:identity.users.manage')
            ->name('users.password');
        Route::delete('/users/{user}/sessions', [UserManagementController::class, 'sessions'])
            ->middleware('permission:identity.sessions.manage')
            ->name('users.sessions.revoke');

        Route::post('/shared-devices/codes', [SharedDeviceController::class, 'generateCode'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.codes.store');
        Route::get('/shared-devices', [SharedDeviceController::class, 'managedIndex'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.index');
        Route::delete('/shared-devices/{sharedDevice}/pairing', [SharedDeviceController::class, 'revokeDevice'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.revoke');

        Route::post('/shared-device/activity', [SharedDeviceController::class, 'activity'])
            ->middleware(['shared.device:native', 'auth:sanctum', 'shared.session'])
            ->name('shared-device.activity');
        Route::get('/reference/options', ReferenceOptionsController::class)->name('reference-options.index');

        Route::get('/audit/entries', [AuditEntryController::class, 'index'])->name('audit.entries.index');

        Route::get('/reports/sources', [ReportSourceController::class, 'index'])->name('reports.sources.index');
        Route::post('/reports/{source}/previews', [ReportSourceController::class, 'preview'])
            ->middleware('throttle:reporting')
            ->name('reports.previews.store');

        Route::get('/report-presets', [ReportPresetController::class, 'index'])->name('report-presets.index');
        Route::post('/report-presets', [ReportPresetController::class, 'store'])->name('report-presets.store');
        Route::get('/report-presets/{reportPreset}', [ReportPresetController::class, 'show'])->name('report-presets.show');
        Route::patch('/report-presets/{reportPreset}', [ReportPresetController::class, 'update'])->name('report-presets.update');
        Route::delete('/report-presets/{reportPreset}', [ReportPresetController::class, 'destroy'])->name('report-presets.destroy');

        Route::post('/reports/{source}/exports', [ReportExportController::class, 'store'])
            ->middleware('throttle:reporting')
            ->name('report-exports.store');
        Route::get('/report-exports', [ReportExportController::class, 'index'])->name('report-exports.index');
        Route::get('/report-exports/{reportExport}', [ReportExportController::class, 'show'])->name('report-exports.show');
        Route::post('/report-exports/{reportExport}/temporary-links', [ReportExportController::class, 'share'])
            ->name('report-exports.share');

        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::patch('/departments/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
        Route::get('/departments/{department}/localities', [LocalityController::class, 'index'])
            ->name('departments.localities.index');
        Route::post('/departments/{department}/localities', [LocalityController::class, 'store'])
            ->name('departments.localities.store');
        Route::patch('/localities/{locality}', [LocalityController::class, 'update'])
            ->name('localities.update');

        Route::get('/production-units', [ProductionUnitController::class, 'index'])
            ->name('production-units.index');
        Route::post('/production-units', [ProductionUnitController::class, 'store'])
            ->name('production-units.store');
        Route::get('/production-units/{productionUnit}', [ProductionUnitController::class, 'show'])
            ->name('production-units.show');
        Route::patch('/production-units/{productionUnit}', [ProductionUnitController::class, 'update'])
            ->name('production-units.update');
        Route::patch('/production-units/{productionUnit}/status', [ProductionUnitStatusController::class, 'update'])
            ->name('production-units.status.update');
        Route::get('/production-units/{productionUnit}/poultry-houses', [PoultryHouseController::class, 'index'])
            ->name('production-units.poultry-houses.index');
        Route::get('/production-units/{productionUnit}/egg-stock', [EggStockController::class, 'balance'])->name('egg-stock.balance');
        Route::get('/production-units/{productionUnit}/egg-stock/movements', [EggStockController::class, 'index'])->name('egg-stock.index');
        Route::post('/production-units/{productionUnit}/egg-stock/receipts', [EggStockController::class, 'receipt'])->name('egg-stock.receipts.store');
        Route::post('/production-units/{productionUnit}/egg-stock/issues', [EggStockController::class, 'issue'])->name('egg-stock.issues.store');
        Route::get('/egg-stock/movements/{movement}', [EggStockController::class, 'show'])->name('egg-stock.show');
        Route::patch('/egg-stock/movements/{movement}', [EggStockController::class, 'update'])->name('egg-stock.update');
        Route::post('/egg-stock/movements/{movement}/cancellation', [EggStockController::class, 'cancel'])->name('egg-stock.cancel');
        Route::post('/production-units/{productionUnit}/poultry-houses', [PoultryHouseController::class, 'store'])
            ->name('production-units.poultry-houses.store');
        Route::get('/poultry-houses/{poultryHouse}', [PoultryHouseController::class, 'show'])
            ->name('poultry-houses.show');
        Route::patch('/poultry-houses/{poultryHouse}', [PoultryHouseController::class, 'update'])
            ->name('poultry-houses.update');
        Route::patch('/poultry-houses/{poultryHouse}/status', [PoultryHouseStatusController::class, 'update'])
            ->name('poultry-houses.status.update');

        Route::get('/poultry-houses/{poultryHouse}/maintenances', [MaintenanceController::class, 'index'])->name('maintenances.index');
        Route::get('/poultry-houses/{poultryHouse}/maintenances/latest', [MaintenanceController::class, 'latest'])->name('maintenances.latest');
        Route::post('/poultry-houses/{poultryHouse}/maintenances', [MaintenanceController::class, 'store'])->name('maintenances.store');
        Route::get('/maintenances/{maintenance}', [MaintenanceController::class, 'show'])->name('maintenances.show');
        Route::patch('/maintenances/{maintenance}', [MaintenanceController::class, 'update'])->name('maintenances.update');
        Route::post('/maintenances/{maintenance}/cancellation', [MaintenanceController::class, 'cancel'])->name('maintenances.cancel');

        Route::get('/administration', AdminController::class)->name('admin.dashboard');

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::patch('/suppliers/{supplier}/status', [SupplierStatusController::class, 'update'])->name('suppliers.status.update');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/medicines', [MedicineController::class, 'index'])->name('medicines.index');
        Route::post('/medicines', [MedicineController::class, 'store'])->name('medicines.store');
        Route::get('/vacunas', [VaccineController::class, 'index'])->name('vaccines.index');
        Route::post('/vacunas', [VaccineController::class, 'store'])->name('vaccines.store');
        Route::get('/vacunas/{vaccine}', [VaccineController::class, 'show'])->name('vaccines.show');
        Route::patch('/vacunas/{vaccine}', [VaccineController::class, 'update'])->name('vaccines.update');
        Route::patch('/vacunas/{vaccine}/estado', [VaccineStatusController::class, 'update'])->name('vaccines.status.update');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/status', [ProductStatusController::class, 'update'])->name('products.status.update');

        Route::get('/stock-locations', [StockLocationController::class, 'index'])->name('stock-locations.index');
        Route::post('/stock-locations', [StockLocationController::class, 'store'])->name('stock-locations.store');
        Route::get('/stock-locations/{stockLocation}', [StockLocationController::class, 'show'])->name('stock-locations.show');
        Route::patch('/stock-locations/{stockLocation}', [StockLocationController::class, 'update'])->name('stock-locations.update');
        Route::patch('/stock-locations/{stockLocation}/status', [StockLocationStatusController::class, 'update'])->name('stock-locations.status.update');

        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            Route::get('/balances', [InventoryReadController::class, 'balances'])->name('balances.index');
            Route::patch('/balances/{stockBalance}/minimum-stock', [InventoryReadController::class, 'minimum'])->name('balances.minimum');
            Route::get('/movements', [InventoryReadController::class, 'movements'])->name('movements.index');
            Route::get('/movements/{inventoryMovement}', [InventoryReadController::class, 'movement'])->name('movements.show');
            Route::post('/receipts', [InventoryMovementController::class, 'receive'])->name('receipts.store');
            Route::post('/issues', [InventoryMovementController::class, 'issue'])->name('issues.store');
            Route::post('/losses', [InventoryMovementController::class, 'loss'])->name('losses.store');
            Route::post('/adjustments', [InventoryMovementController::class, 'adjust'])->name('adjustments.store');
            Route::post('/transfers', [InventoryMovementController::class, 'transfer'])->name('transfers.store');
            Route::post('/movements/{inventoryMovement}/reversals', [InventoryMovementController::class, 'reverse'])->name('movements.reversals.store');
        });
    });

    Route::get('/report-exports/{reportExport}/download', [ReportExportController::class, 'download'])
        ->name('report-exports.download');
});
