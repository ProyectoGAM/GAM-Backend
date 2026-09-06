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
use App\Modules\IdentityAndAccess\Http\Controllers\SharedDeviceController;
use App\Modules\IdentityAndAccess\Http\Controllers\UserManagementController;
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
    Route::prefix('autenticacion')->name('auth.')->middleware(['throttle:auth', 'throttle:auth-account'])->group(function (): void {
        Route::post('/inicio-sesion', [AuthController::class, 'login'])->name('login');
    });

    Route::post('/dispositivos-compartidos/vinculacion', [SharedDeviceController::class, 'redeemCode'])
        ->middleware('throttle:pairing')
        ->name('shared-devices.pair');

    Route::get('/dispositivo-compartido', [SharedDeviceController::class, 'status'])
        ->middleware('shared.device:native')
        ->name('shared-device.status');
    Route::get('/dispositivo-compartido/usuarios', [SharedDeviceController::class, 'users'])
        ->middleware(['shared.device:native', 'throttle:shared-device'])
        ->name('shared-device.users');
    Route::post('/dispositivo-compartido/inicio-sesion-pin', [SharedDeviceController::class, 'loginPin'])
        ->middleware(['shared.device:native', 'throttle:pin'])
        ->name('shared-device.pin-login');
    Route::post('/dispositivo-compartido/finalizar-sesion', [SharedDeviceController::class, 'finalize'])
        ->middleware('shared.device:native')
        ->name('shared-device.finalize');
    Route::delete('/dispositivo-compartido/vinculacion', [SharedDeviceController::class, 'revokeLocal'])
        ->middleware('shared.device:native')
        ->name('shared-device.local-revoke');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/mi-perfil', [AuthController::class, 'me'])->name('me');
        Route::get('/mis-sesiones', [AuthController::class, 'sessions'])->name('auth.sessions');
        Route::delete('/mis-sesiones/{session}', [AuthController::class, 'revokeSession'])->name('auth.sessions.revoke');
        Route::post('/autenticacion/cerrar-sesion', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/autenticacion/confirmar-password', [AuthController::class, 'confirmPassword'])->name('auth.confirm-password');

        Route::get('/usuarios', [UserManagementController::class, 'index'])
            ->middleware('permission:identity.users.manage')
            ->name('users.index');
        Route::post('/usuarios', [UserManagementController::class, 'store'])
            ->middleware('permission:identity.users.manage')
            ->name('users.store');
        Route::patch('/usuarios/{user}/estado', [UserManagementController::class, 'status'])
            ->middleware('permission:identity.users.manage')
            ->withTrashed()
            ->name('users.status');
        Route::put('/usuarios/{user}/roles', [UserManagementController::class, 'roles'])
            ->middleware('permission:identity.users.manage')
            ->name('users.roles');
        Route::put('/usuarios/{user}/pin', [UserManagementController::class, 'setPin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.update');
        Route::delete('/usuarios/{user}/pin', [UserManagementController::class, 'deletePin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.destroy');
        Route::post('/usuarios/{user}/pin/desbloqueo', [UserManagementController::class, 'unlockPin'])
            ->middleware('permission:identity.pins.manage')
            ->name('users.pin.unlock');
        Route::put('/usuarios/{user}/password', [UserManagementController::class, 'password'])
            ->middleware('permission:identity.users.manage')
            ->name('users.password');
        Route::delete('/usuarios/{user}/sesiones', [UserManagementController::class, 'sessions'])
            ->middleware('permission:identity.sessions.manage')
            ->name('users.sessions.revoke');

        Route::post('/dispositivos-compartidos/codigos', [SharedDeviceController::class, 'generateCode'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.codes.store');
        Route::get('/dispositivos-compartidos', [SharedDeviceController::class, 'managedIndex'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.index');
        Route::delete('/dispositivos-compartidos/{sharedDevice}/vinculacion', [SharedDeviceController::class, 'revokeDevice'])
            ->middleware('permission:identity.shared-devices.manage')
            ->name('shared-devices.revoke');

        Route::post('/dispositivo-compartido/actividad', [SharedDeviceController::class, 'activity'])
            ->middleware(['shared.device:native', 'auth:sanctum', 'shared.session'])
            ->name('shared-device.activity');

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
            Route::get('/reservas', [InventoryReadController::class, 'reservations'])->name('reservations.index');
            Route::post('/ingresos', [InventoryMovementController::class, 'receive'])->name('receipts.store');
            Route::post('/salidas', [InventoryMovementController::class, 'issue'])->name('issues.store');
            Route::post('/perdidas', [InventoryMovementController::class, 'loss'])->name('losses.store');
            Route::post('/ajustes', [InventoryMovementController::class, 'adjust'])->name('adjustments.store');
            Route::post('/transferencias', [InventoryMovementController::class, 'transfer'])->name('transfers.store');
            Route::post('/movimientos/{inventoryMovement}/reversiones', [InventoryMovementController::class, 'reverse'])->name('movimientos.reversals.store');
            Route::post('/reservas', [StockReservationController::class, 'store'])->name('reservations.store');
            Route::post('/reservas/{stockReservation}/liberaciones', [StockReservationController::class, 'release'])->name('reservations.releases.store');
            Route::post('/reservas/{stockReservation}/consumos', [StockReservationController::class, 'consume'])->name('reservations.consumptions.store');
        });
    });

    Route::get('/exportaciones-reportes/{reportExport}/descarga', [ReportExportController::class, 'download'])
        ->name('report-exports.download');
});
