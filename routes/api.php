<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\AuthController;




#region Metodos para User

Route::post('user/login-user', [AuthController::class, 'login']);
Route::post('user/register-user', [AuthController::class, 'registerUser']);


Route::middleware('auth:user')->group(function () {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::get('vehicles', [VehicleController::class, 'index']);
    Route::post('user/session-close', [AuthController::class, 'userLogout']);

});

#endregion


#region Metodos para User


#endregion