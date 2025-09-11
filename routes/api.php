<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;




#region Metodos para User

Route::post('user/login-user', [AuthController::class, 'login']);
Route::post('user/register-user', [AuthController::class, 'registerUser']);


Route::middleware('auth:user')->group(function () {

    Route::post('user/session-close', [AuthController::class, 'userLogout']);
    Route::get('user/profile', [UserController::class, 'profile']); // ✅ perfil user

    Route::get('get-all-customers', [CustomerController::class, 'GetAllCustomers']);
    Route::get('customers-by-id/{id}', [CustomerController::class, 'GetCustomerById']);
    Route::delete('customers-delete/{id}', [CustomerController::class, 'Customerdestroy']);
    Route::patch('customers-inactive/{id}', [CustomerController::class, 'toggleActiveCustomer']);



});

#endregion


#region Metodos para User
// Registro y login de clientes (público)
Route::post('CustomerUser/register', [CustomerAuthController::class, 'customerRegister']);
Route::post('customer/login', [CustomerAuthController::class, 'customerLogin']);

// Rutas protegidas para clientes
Route::middleware('auth:customer')->group(function () {
    Route::post('customer/logout', [CustomerAuthController::class, 'customerLogout']);
    Route::get('customer/profile', [CustomerController::class, 'profile']); // ✅ perfil customer

});

#endregion