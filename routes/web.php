<?php

use App\Http\Controllers\IdentityAndAccess\AdminController;
use App\Http\Controllers\IdentityAndAccess\AuthController;
use App\Http\Controllers\IdentityAndAccess\SharedDeviceController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/status', function (): JsonResponse {
    return response()->json([
        'status' => 'ok',
        'message' => 'La aplicación está disponible.',
    ]);
})->name('health');

Route::get('/administration', AdminController::class)
    ->middleware('auth:sanctum')
    ->name('admin.dashboard');

Route::prefix('api/v1/auth')->middleware('web')->group(function (): void {
    Route::post('/web/login', [AuthController::class, 'webLogin'])->middleware(['throttle:auth', 'throttle:auth-account'])->name('web.auth.login');
    Route::post('/web/logout', [AuthController::class, 'webLogout'])->middleware('auth')->name('web.auth.logout');
    Route::post('/web/confirm-password', [AuthController::class, 'confirmPassword'])->middleware('auth')->name('web.auth.confirm-password');
    Route::post('/web/pairing', [SharedDeviceController::class, 'redeemWebCode'])->middleware('throttle:pairing')->name('web.shared-devices.pair');
});

Route::prefix('api/v1/shared-device')->middleware('web')->group(function (): void {
    Route::get('/web', [SharedDeviceController::class, 'status'])
        ->middleware('shared.device:web')
        ->name('web.shared-device.status');
    Route::get('/web/users', [SharedDeviceController::class, 'users'])
        ->middleware(['shared.device:web', 'throttle:shared-device'])
        ->name('web.shared-device.users');
    Route::post('/web/login-pin', [SharedDeviceController::class, 'webLoginPin'])
        ->middleware(['shared.device:web', 'throttle:pin'])
        ->name('web.shared-device.pin-login');
    Route::post('/web/activity', [SharedDeviceController::class, 'activity'])
        ->middleware(['shared.device:web', 'auth', 'shared.session'])
        ->name('web.shared-device.activity');
    Route::post('/web/finalize', [SharedDeviceController::class, 'finalize'])
        ->middleware(['shared.device:web', 'auth', 'shared.session'])
        ->name('web.shared-device.finalize');
    Route::delete('/web/pairing', [SharedDeviceController::class, 'revokeLocal'])
        ->middleware('shared.device:web')
        ->name('web.shared-device.local-revoke');
});
