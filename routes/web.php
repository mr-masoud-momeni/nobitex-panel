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

    Route::resource('/register', 'App\\Http\\Controllers\\admin\\UserController');
    Route::post('/register/{uuid}/regenerate-password', 'App\\Http\\Controllers\\admin\\UserController@regeneratePassword')
        ->name('register.password.regenerate');

    Route::resource('/Permission', 'App\\Http\\Controllers\\admin\\PermissionController');
    Route::resource('/notification', 'App\\Http\\Controllers\\admin\\NotificationController');
    Route::resource('/role', 'App\\Http\\Controllers\\admin\\RoleController');
    Route::resource('/email', 'App\\Http\\Controllers\\admin\\SendEmail');
    Route::resource('/email-group', 'App\\Http\\Controllers\\admin\\EmailGroupController');
    Route::resource('/menu', 'App\\Http\\Controllers\\admin\\MenuController');

    Route::get('/strategy', 'App\\Http\\Controllers\\admin\\StrategyController@index')->name('strategy.index');
    Route::get('/strategy/create', 'App\\Http\\Controllers\\admin\\StrategyController@create')->name('strategy.create');
    Route::post('/strategy', 'App\\Http\\Controllers\\admin\\StrategyController@store')->name('strategy.store');
    Route::post('/strategy/{strategy}/duplicate', 'App\\Http\\Controllers\\admin\\StrategyController@duplicate')->name('strategy.duplicate');

    Route::get('/indicator-lab', 'App\\Http\\Controllers\\admin\\IndicatorLabController@index')->name('indicator-lab.index');
    Route::post('/indicator-lab/test', 'App\\Http\\Controllers\\admin\\IndicatorLabController@test')->name('indicator-lab.test');

    Route::get('/trade', 'App\\Http\\Controllers\\admin\\TradeController@index')->name('trade.index');
    Route::get('/trade/create', 'App\\Http\\Controllers\\admin\\TradeController@create')->name('trade.create');
    Route::post('/trade', 'App\\Http\\Controllers\\admin\\TradeController@store')->name('trade.store');
    Route::get('/trade/{trade}/edit', 'App\\Http\\Controllers\\admin\\TradeController@edit')->name('trade.edit');
    Route::put('/trade/{trade}', 'App\\Http\\Controllers\\admin\\TradeController@update')->name('trade.update');
    Route::post('/trade/{trade}/duplicate', 'App\\Http\\Controllers\\admin\\TradeController@duplicate')->name('trade.duplicate');
    Route::get('/trade/{trade}', 'App\\Http\\Controllers\\admin\\TradeController@show')->name('trade.show');
    Route::delete('/trade/{trade}', 'App\\Http\\Controllers\\admin\\TradeController@destroy')->name('trade.destroy');
    Route::post('/trade/{trade}/start', 'App\\Http\\Controllers\\admin\\TradeController@start')->name('trade.start');
    Route::post('/trade/{trade}/stop', 'App\\Http\\Controllers\\admin\\TradeController@stop')->name('trade.stop');

    Route::post('/upload-image', 'App\\Http\\Controllers\\admin\\panelAdmin@UploadImageInText')->name('uploadImage');
});

Route::middleware('auth')->get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');
