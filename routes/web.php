<?php

use App\Http\Controllers\IdentityAndAccess\AdminController;
use App\Http\Controllers\IdentityAndAccess\AuthController;
use App\Http\Controllers\IdentityAndAccess\SharedDeviceController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/estado', function (): JsonResponse {
    return response()->json([
        'estado' => 'ok',
        'message' => 'La aplicación está disponible.',
    ]);
})->name('health');

Route::get('/administracion', AdminController::class)
    ->middleware('auth:sanctum')
    ->name('admin.dashboard');

Route::prefix('api/v1/autenticacion')->middleware('web')->group(function (): void {
    Route::post('/web/inicio-sesion', [AuthController::class, 'webLogin'])->middleware(['throttle:auth', 'throttle:auth-account'])->name('web.auth.login');
    Route::post('/web/cerrar-sesion', [AuthController::class, 'webLogout'])->middleware('auth')->name('web.auth.logout');
    Route::post('/web/confirmar-password', [AuthController::class, 'confirmPassword'])->middleware('auth')->name('web.auth.confirm-password');
    Route::post('/web/vinculacion', [SharedDeviceController::class, 'redeemWebCode'])->middleware('throttle:pairing')->name('web.shared-devices.pair');
});

Route::prefix('api/v1/dispositivo-compartido')->middleware('web')->group(function (): void {
    Route::get('/web', [SharedDeviceController::class, 'status'])
        ->middleware('shared.device:web')
        ->name('web.shared-device.status');
    Route::get('/web/usuarios', [SharedDeviceController::class, 'users'])
        ->middleware(['shared.device:web', 'throttle:shared-device'])
        ->name('web.shared-device.users');
    Route::post('/web/inicio-sesion-pin', [SharedDeviceController::class, 'webLoginPin'])
        ->middleware(['shared.device:web', 'throttle:pin'])
        ->name('web.shared-device.pin-login');
    Route::post('/web/actividad', [SharedDeviceController::class, 'activity'])
        ->middleware(['shared.device:web', 'auth', 'shared.session'])
        ->name('web.shared-device.activity');
    Route::post('/web/finalizar-sesion', [SharedDeviceController::class, 'finalize'])
        ->middleware(['shared.device:web', 'auth', 'shared.session'])
        ->name('web.shared-device.finalize');
    Route::delete('/web/vinculacion', [SharedDeviceController::class, 'revokeLocal'])
        ->middleware('shared.device:web')
        ->name('web.shared-device.local-revoke');
});
