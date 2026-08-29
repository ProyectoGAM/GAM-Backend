<?php

use App\Modules\IdentityAndAccess\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin', AdminController::class)
    ->middleware(['auth:sanctum', 'permission:admin.dashboard.view'])
    ->name('admin.dashboard');
