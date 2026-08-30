<?php

use App\Modules\AuditAndTraceability\Http\Controllers\AuditEntryController;
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
use App\Modules\Inventory\Http\Controllers\StockReservationController;
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
        Route::get('/mi-perfil', [AuthController::class, 'me'])->name('me');
        Route::post('/autenticacion/cerrar-sesion', [AuthController::class, 'logout'])->name('auth.logout');

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

        Route::get('/departamentos', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departamentos', [DepartmentController::class, 'store'])->name('departments.store');
        Route::patch('/departamentos/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
        Route::get('/departamentos/{department}/localidades', [LocalityController::class, 'index'])
            ->name('departments.localities.index');
        Route::post('/departamentos/{department}/localidades', [LocalityController::class, 'store'])
            ->name('departments.localities.store');
        Route::patch('/localidades/{locality}', [LocalityController::class, 'update'])
            ->name('localities.update');

        Route::get('/unidades-productivas', [ProductionUnitController::class, 'index'])
            ->name('production-units.index');
        Route::post('/unidades-productivas', [ProductionUnitController::class, 'store'])
            ->name('production-units.store');
        Route::get('/unidades-productivas/{productionUnit}', [ProductionUnitController::class, 'show'])
            ->name('production-units.show');
        Route::patch('/unidades-productivas/{productionUnit}', [ProductionUnitController::class, 'update'])
            ->name('production-units.update');
        Route::patch('/unidades-productivas/{productionUnit}/estado', [ProductionUnitStatusController::class, 'update'])
            ->name('production-units.status.update');
        Route::get('/unidades-productivas/{productionUnit}/galpones', [PoultryHouseController::class, 'index'])
            ->name('production-units.poultry-houses.index');
        Route::post('/unidades-productivas/{productionUnit}/galpones', [PoultryHouseController::class, 'store'])
            ->name('production-units.poultry-houses.store');
        Route::get('/galpones/{poultryHouse}', [PoultryHouseController::class, 'show'])
            ->name('poultry-houses.show');
        Route::patch('/galpones/{poultryHouse}', [PoultryHouseController::class, 'update'])
            ->name('poultry-houses.update');
        Route::patch('/galpones/{poultryHouse}/estado', [PoultryHouseStatusController::class, 'update'])
            ->name('poultry-houses.status.update');

        Route::get('/administracion', AdminController::class)->name('admin.dashboard');

        Route::get('/proveedores', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/proveedores', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/proveedores/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::patch('/proveedores/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::patch('/proveedores/{supplier}/estado', [SupplierStatusController::class, 'update'])->name('suppliers.status.update');

        Route::get('/productos', [ProductController::class, 'index'])->name('products.index');
        Route::post('/productos', [ProductController::class, 'store'])->name('products.store');
        Route::get('/productos/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('/productos/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/productos/{product}/estado', [ProductStatusController::class, 'update'])->name('products.status.update');

        Route::get('/ubicaciones-stock', [StockLocationController::class, 'index'])->name('stock-locations.index');
        Route::post('/ubicaciones-stock', [StockLocationController::class, 'store'])->name('stock-locations.store');
        Route::get('/ubicaciones-stock/{stockLocation}', [StockLocationController::class, 'show'])->name('stock-locations.show');
        Route::patch('/ubicaciones-stock/{stockLocation}', [StockLocationController::class, 'update'])->name('stock-locations.update');
        Route::patch('/ubicaciones-stock/{stockLocation}/estado', [StockLocationStatusController::class, 'update'])->name('stock-locations.status.update');

        Route::prefix('inventario')->name('inventory.')->group(function (): void {
            Route::get('/saldos', [InventoryReadController::class, 'balances'])->name('balances.index');
            Route::patch('/saldos/{stockBalance}/stock-minimo', [InventoryReadController::class, 'minimum'])->name('balances.minimum');
            Route::get('/movimientos', [InventoryReadController::class, 'movements'])->name('movements.index');
            Route::get('/movimientos/{inventoryMovement}', [InventoryReadController::class, 'movement'])->name('movements.show');
            Route::get('/reservas', [InventoryReadController::class, 'reservations'])->name('reservations.index');
            Route::post('/ingresos', [InventoryMovementController::class, 'receive'])->name('receipts.store');
            Route::post('/salidas', [InventoryMovementController::class, 'issue'])->name('issues.store');
            Route::post('/perdidas', [InventoryMovementController::class, 'loss'])->name('losses.store');
            Route::post('/ajustes', [InventoryMovementController::class, 'adjust'])->name('adjustments.store');
            Route::post('/transferencias', [InventoryMovementController::class, 'transfer'])->name('transfers.store');
            Route::post('/movimientos/{inventoryMovement}/reversiones', [InventoryMovementController::class, 'reverse'])->name('movements.reversals.store');
            Route::post('/reservas', [StockReservationController::class, 'store'])->name('reservations.store');
            Route::post('/reservas/{stockReservation}/liberaciones', [StockReservationController::class, 'release'])->name('reservations.releases.store');
            Route::post('/reservas/{stockReservation}/consumos', [StockReservationController::class, 'consume'])->name('reservations.consumptions.store');
        });
    });

    Route::get('/exportaciones-reportes/{reportExport}/descarga', [ReportExportController::class, 'download'])
        ->name('report-exports.download');
});
