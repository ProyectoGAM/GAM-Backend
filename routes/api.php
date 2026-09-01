<?php

use App\Modules\AuditAndTraceability\Http\Controllers\AuditEntryController;
use App\Modules\FarmStructure\Http\Controllers\MaintenanceController;
use App\Modules\FarmStructure\Http\Controllers\PoultryHouseController;
use App\Modules\FarmStructure\Http\Controllers\PoultryHouseStatusController;
use App\Modules\FarmStructure\Http\Controllers\ProductionUnitController;
use App\Modules\FarmStructure\Http\Controllers\ProductionUnitStatusController;
use App\Modules\Geography\Http\Controllers\DepartmentController;
use App\Modules\Geography\Http\Controllers\LocalityController;
use App\Modules\IdentityAndAccess\Http\Controllers\AdminController;
use App\Modules\IdentityAndAccess\Http\Controllers\AuthController;
use App\Modules\Inventory\Http\Controllers\InventoryMovementController;
use App\Modules\Inventory\Http\Controllers\InventoryReadController;
use App\Modules\Inventory\Http\Controllers\StockLocationController;
use App\Modules\Inventory\Http\Controllers\StockLocationStatusController;
use App\Modules\Lots\Http\Controllers\BreedController;
use App\Modules\Lots\Http\Controllers\EggCollectionController;
use App\Modules\Lots\Http\Controllers\FlockController;
use App\Modules\Lots\Http\Controllers\FlockRedistributionController;
use App\Modules\Lots\Http\Controllers\FlockStatusController;
use App\Modules\Lots\Http\Controllers\MortalityCategoryController;
use App\Modules\Lots\Http\Controllers\MortalityController;
use App\Modules\ReferenceData\Http\Controllers\ReferenceOptionsController;
use App\Modules\ReportingAndAnalytics\Http\Controllers\ReportExportController;
use App\Modules\ReportingAndAnalytics\Http\Controllers\ReportPresetController;
use App\Modules\ReportingAndAnalytics\Http\Controllers\ReportSourceController;
use App\Modules\SuppliersAndCatalogs\Http\Controllers\ProductController;
use App\Modules\SuppliersAndCatalogs\Http\Controllers\ProductStatusController;
use App\Modules\SuppliersAndCatalogs\Http\Controllers\SupplierController;
use App\Modules\SuppliersAndCatalogs\Http\Controllers\SupplierStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('autenticacion')->name('auth.')->middleware('throttle:auth')->group(function (): void {
        Route::post('/registro', [AuthController::class, 'register'])->name('register');
        Route::post('/inicio-sesion', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::name('lots.')->group(function (): void {
            Route::get('/lotes', [FlockController::class, 'index'])->name('index');
            Route::post('/lotes', [FlockController::class, 'store'])->name('store');
            Route::get('/lotes/{lote}', [FlockController::class, 'show'])->name('show');
            Route::patch('/lotes/{lote}', [FlockController::class, 'update'])->name('update');
            Route::patch('/lotes/{lote}/estado', [FlockStatusController::class, 'update'])->name('status');
            Route::post('/lotes/{lote}/finalizacion', [FlockStatusController::class, 'finalize'])->name('finalize');
            Route::post('/lotes/{lote}/redistribuciones', [FlockRedistributionController::class, 'store'])->name('redistribute');
            Route::post('/redistribuciones/{redistribucion}/reversiones', [FlockRedistributionController::class, 'reverse'])->name('redistributions.reverse');
            Route::get('/lotes/{lote}/historial', [FlockController::class, 'history'])->name('history');
            Route::get('/galpones/{galpon}/lotes', [FlockController::class, 'index'])->name('by-house');
            Route::get('/razas', [BreedController::class, 'index'])->name('breeds.index');
            Route::post('/razas', [BreedController::class, 'store'])->name('breeds.store');
            Route::patch('/razas/{raza}', [BreedController::class, 'update'])->name('breeds.update');
            Route::get('/categorias-mortalidad', [MortalityCategoryController::class, 'index'])->name('mortality-categories.index');
            Route::post('/categorias-mortalidad', [MortalityCategoryController::class, 'store'])->name('mortality-categories.store');
            Route::patch('/categorias-mortalidad/{categoria}', [MortalityCategoryController::class, 'update'])->name('mortality-categories.update');
            Route::get('/mortalidades', [MortalityController::class, 'index'])->name('mortality.index');
            Route::get('/mortalidades/{mortalidad}', [MortalityController::class, 'show'])->name('mortality.show');
            Route::get('/lotes/{lote}/mortalidades', [MortalityController::class, 'byFlock'])->name('mortality.by-flock');
            Route::post('/lotes/{lote}/mortalidades', [MortalityController::class, 'store'])->name('mortality.store');
            Route::patch('/mortalidades/{mortalidad}', [MortalityController::class, 'update'])->name('mortality.update');
            Route::post('/mortalidades/{mortalidad}/cancelacion', [MortalityController::class, 'cancel'])->name('mortality.cancel');
            Route::get('/recolecciones', [EggCollectionController::class, 'indexAll'])->name('collections.index-all');
            Route::get('/recolecciones/metricas', [EggCollectionController::class, 'metricsAll'])->name('collections.metrics');
            Route::get('/lotes/{lote}/recolecciones', [EggCollectionController::class, 'index'])->name('collections.index');
            Route::get('/recolecciones/{recoleccion}', [EggCollectionController::class, 'show'])->name('collections.show');
            Route::post('/lotes/{lote}/recolecciones', [EggCollectionController::class, 'store'])->name('collections.store');
            Route::patch('/recolecciones/{recoleccion}', [EggCollectionController::class, 'update'])->name('collections.update');
            Route::post('/recolecciones/{recoleccion}/cancelacion', [EggCollectionController::class, 'cancel'])->name('collections.cancel');
            Route::post('/recolecciones/{recoleccion}/perdidas', [EggCollectionController::class, 'loss'])->name('collections.losses.store');
            Route::post('/recolecciones/{recoleccion}/perdidas/{movimiento}/cancelacion', [EggCollectionController::class, 'cancelLoss'])->name('collections.losses.cancel');
            Route::get('/lotes/{lote}/metricas', [EggCollectionController::class, 'metrics'])->name('metrics');
        });

        Route::get('/mi-perfil', [AuthController::class, 'me'])->name('me');
        Route::post('/autenticacion/cerrar-sesion', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/referencias/opciones', ReferenceOptionsController::class)->name('reference-options.index');

        Route::get('/auditoria/entradas', [AuditEntryController::class, 'index'])->name('audit.entries.index');

        Route::get('/reportes/fuentes', [ReportSourceController::class, 'index'])->name('reports.sources.index');
        Route::post('/reportes/{source}/previsualizaciones', [ReportSourceController::class, 'preview'])
            ->middleware('throttle:reporting')
            ->name('reports.previews.store');

        Route::get('/configuraciones-reportes', [ReportPresetController::class, 'index'])->name('report-presets.index');
        Route::post('/configuraciones-reportes', [ReportPresetController::class, 'store'])->name('report-presets.store');
        Route::get('/configuraciones-reportes/{reportPreset}', [ReportPresetController::class, 'show'])->name('report-presets.show');
        Route::patch('/configuraciones-reportes/{reportPreset}', [ReportPresetController::class, 'update'])->name('report-presets.update');
        Route::delete('/configuraciones-reportes/{reportPreset}', [ReportPresetController::class, 'destroy'])->name('report-presets.destroy');

        Route::post('/reportes/{source}/exportaciones', [ReportExportController::class, 'store'])
            ->middleware('throttle:reporting')
            ->name('report-exports.store');
        Route::get('/exportaciones-reportes', [ReportExportController::class, 'index'])->name('report-exports.index');
        Route::get('/exportaciones-reportes/{reportExport}', [ReportExportController::class, 'show'])->name('report-exports.show');
        Route::post('/exportaciones-reportes/{reportExport}/enlaces-temporales', [ReportExportController::class, 'share'])
            ->name('report-exports.share');

        Route::get('/departamentos', [DepartmentController::class, 'index'])->name('departamentos.index');
        Route::post('/departamentos', [DepartmentController::class, 'store'])->name('departamentos.store');
        Route::patch('/departamentos/{departamento}', [DepartmentController::class, 'update'])
            ->name('departamentos.update');
        Route::get('/departamentos/{departamento}/localidades', [LocalityController::class, 'index'])
            ->name('departamentos.localidades.index');
        Route::post('/departamentos/{departamento}/localidades', [LocalityController::class, 'store'])
            ->name('departamentos.localidades.store');
        Route::patch('/localidades/{localidad}', [LocalityController::class, 'update'])
            ->name('localidades.update');

        Route::get('/unidades-productivas', [ProductionUnitController::class, 'index'])
            ->name('production-units.index');
        Route::post('/unidades-productivas', [ProductionUnitController::class, 'store'])
            ->name('production-units.store');
        Route::get('/unidades-productivas/{unidadProductiva}', [ProductionUnitController::class, 'show'])
            ->name('production-units.show');
        Route::patch('/unidades-productivas/{unidadProductiva}', [ProductionUnitController::class, 'update'])
            ->name('production-units.update');
        Route::patch('/unidades-productivas/{unidadProductiva}/estado', [ProductionUnitStatusController::class, 'update'])
            ->name('production-units.estado.update');
        Route::get('/unidades-productivas/{unidadProductiva}/galpones', [PoultryHouseController::class, 'index'])
            ->name('production-units.poultry-houses.index');
        Route::post('/unidades-productivas/{unidadProductiva}/galpones', [PoultryHouseController::class, 'store'])
            ->name('production-units.poultry-houses.store');
        Route::get('/galpones/{poultryHouse}', [PoultryHouseController::class, 'show'])
            ->name('poultry-houses.show');
        Route::patch('/galpones/{poultryHouse}', [PoultryHouseController::class, 'update'])
            ->name('poultry-houses.update');
        Route::patch('/galpones/{poultryHouse}/estado', [PoultryHouseStatusController::class, 'update'])
            ->name('poultry-houses.estado.update');

        Route::get('/galpones/{poultryHouse}/mantenimientos', [MaintenanceController::class, 'index'])->name('maintenances.index');
        Route::get('/galpones/{poultryHouse}/mantenimientos/ultimo', [MaintenanceController::class, 'latest'])->name('maintenances.latest');
        Route::post('/galpones/{poultryHouse}/mantenimientos', [MaintenanceController::class, 'store'])->name('maintenances.store');
        Route::get('/mantenimientos/{maintenance}', [MaintenanceController::class, 'show'])->name('maintenances.show');
        Route::patch('/mantenimientos/{maintenance}', [MaintenanceController::class, 'update'])->name('maintenances.update');
        Route::post('/mantenimientos/{maintenance}/cancelacion', [MaintenanceController::class, 'cancel'])->name('maintenances.cancel');

        Route::get('/administracion', AdminController::class)->name('admin.dashboard');

        Route::get('/proveedores', [SupplierController::class, 'index'])->name('proveedores.index');
        Route::post('/proveedores', [SupplierController::class, 'store'])->name('proveedores.store');
        Route::get('/proveedores/{proveedor}', [SupplierController::class, 'show'])->name('proveedores.show');
        Route::patch('/proveedores/{proveedor}', [SupplierController::class, 'update'])->name('proveedores.update');
        Route::patch('/proveedores/{proveedor}/estado', [SupplierStatusController::class, 'update'])->name('proveedores.estado.update');

        Route::get('/productos', [ProductController::class, 'index'])->name('productos.index');
        Route::post('/productos', [ProductController::class, 'store'])->name('productos.store');
        Route::get('/productos/{producto}', [ProductController::class, 'show'])->name('productos.show');
        Route::patch('/productos/{producto}', [ProductController::class, 'update'])->name('productos.update');
        Route::patch('/productos/{producto}/estado', [ProductStatusController::class, 'update'])->name('productos.estado.update');

        Route::get('/ubicaciones-stock', [StockLocationController::class, 'index'])->name('stock-locations.index');
        Route::post('/ubicaciones-stock', [StockLocationController::class, 'store'])->name('stock-locations.store');
        Route::get('/ubicaciones-stock/{ubicacionStock}', [StockLocationController::class, 'show'])->name('stock-locations.show');
        Route::patch('/ubicaciones-stock/{ubicacionStock}', [StockLocationController::class, 'update'])->name('stock-locations.update');
        Route::patch('/ubicaciones-stock/{ubicacionStock}/estado', [StockLocationStatusController::class, 'update'])->name('stock-locations.estado.update');

        Route::prefix('inventario')->name('inventory.')->group(function (): void {
            Route::get('/saldos', [InventoryReadController::class, 'balances'])->name('balances.index');
            Route::patch('/saldos/{stockBalance}/stock-minimo', [InventoryReadController::class, 'minimum'])->name('balances.minimum');
            Route::get('/movimientos', [InventoryReadController::class, 'movimientos'])->name('movimientos.index');
            Route::get('/movimientos/{inventoryMovement}', [InventoryReadController::class, 'movement'])->name('movimientos.show');
            Route::post('/ingresos', [InventoryMovementController::class, 'receive'])->name('receipts.store');
            Route::post('/salidas', [InventoryMovementController::class, 'issue'])->name('issues.store');
            Route::post('/perdidas', [InventoryMovementController::class, 'loss'])->name('losses.store');
            Route::post('/ajustes', [InventoryMovementController::class, 'adjust'])->name('adjustments.store');
            Route::post('/transferencias', [InventoryMovementController::class, 'transfer'])->name('transfers.store');
            Route::post('/movimientos/{inventoryMovement}/reversiones', [InventoryMovementController::class, 'reverse'])->name('movimientos.reversals.store');
        });
    });

    Route::get('/exportaciones-reportes/{reportExport}/descarga', [ReportExportController::class, 'download'])
        ->name('report-exports.download');
});
