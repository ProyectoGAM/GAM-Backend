<?php

use App\Modules\IdentityAndAccess\Http\Controllers\AdminController;
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
