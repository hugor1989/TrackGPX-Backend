<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\AuthController;




#region Metodos para User

Route::post('user/login-user', [AuthController::class, 'login']);
Route::post('user/register-user', [AuthController::class, 'registerUser']);


Route::middleware('auth:user')->group(function () {

    Route::post('user/session-close', [AuthController::class, 'userLogout']);

});

#endregion


#region Metodos para User
// Registro y login de clientes (público)
Route::post('CustomerUser/register', [CustomerAuthController::class, 'customerRegister']);
Route::post('customer/login', [CustomerAuthController::class, 'customerLogin']);

// Rutas protegidas para clientes
Route::middleware('auth:customer')->group(function () {
    Route::post('customer/logout', [CustomerAuthController::class, 'customerLogout']);
    Route::get('customer/profile', [CustomerAuthController::class, 'profile']);
});

#endregion