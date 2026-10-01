<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AdminLoginController;

require __DIR__.'/auth.php';

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminLoginController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminLoginController::class, 'login']);
    Route::post('/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');
});

Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('/dashboard', function () {
        return view('Backend.layouts.Master');
    })->name('admin.dashboard');

    Route::resource('/register', 'App\Http\Controllers\admin\UserController');
    Route::post('/register/{uuid}/regenerate-password', 'App\Http\Controllers\admin\UserController@regeneratePassword')
        ->name('register.password.regenerate');

    Route::resource('/Permission', 'App\Http\Controllers\admin\PermissionController');
    Route::resource('/notification', 'App\Http\Controllers\admin\NotificationController');
    Route::resource('/role', 'App\Http\Controllers\admin\RoleController');
    Route::resource('/email', 'App\Http\Controllers\admin\SendEmail');
    Route::resource('/email-group', 'App\Http\Controllers\admin\EmailGroupController');
    Route::resource('/menu', 'App\Http\Controllers\admin\MenuController');

    Route::get('/strategy', 'App\Http\Controllers\admin\StrategyController@index')->name('strategy.index');
    Route::get('/strategy/create', 'App\Http\Controllers\admin\StrategyController@create')->name('strategy.create');
    Route::post('/strategy', 'App\Http\Controllers\admin\StrategyController@store')->name('strategy.store');

    Route::post('/upload-image', 'App\Http\Controllers\admin\panelAdmin@UploadImageInText')->name('uploadImage');
});

Route::middleware('auth')->get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');
