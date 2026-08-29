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
    });
});
