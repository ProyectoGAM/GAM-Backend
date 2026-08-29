<?php

use App\Modules\IdentityAndAccess\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/administracion', AdminController::class)
    ->middleware('auth:sanctum')
    ->name('admin.dashboard');
