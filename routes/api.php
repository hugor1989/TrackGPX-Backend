<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;



Route::options('{any}', function () {
    return response()->json([], 204);
})->where('any', '.*');


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

    //Gestion de Plans
    Route::get('get-all-plan', [PlanController::class, 'GetAllPlans']);
    Route::post('create-plan', [PlanController::class, 'CreatePlan']);
    Route::get('get-plan-id/{id}', [PlanController::class, 'GetPlanById']);
    Route::put('update-plan/{id}', [PlanController::class, 'PlanUpdateData']);
    Route::delete('delete-plan-id/{id}', [PlanController::class, 'PlanDeleteById']);

});

#endregion


#region Metodos para User
// Registro y login de clientes (público)
Route::post('CustomerUser/register', [CustomerAuthController::class, 'customerRegister']);
Route::post('customer/login', [CustomerAuthController::class, 'customerLogin']);
Route::post('verify-otp', [CustomerAuthController::class, 'verifyOtp']);

// Rutas protegidas para clientes
Route::middleware('auth:customer')->group(function () {
    Route::post('customer/logout', [CustomerAuthController::class, 'customerLogout']);
    Route::get('customer/profile', [CustomerController::class, 'profile']); // ✅ perfil customer

    //get plAN
    Route::get('customer/get-all-plans', [PlanController::class, 'GetAllPlans']);

    //Create Subscription
    Route::post('subscription/create-subscription', [SubscriptionController::class, 'createSubscription']);

    //Actualizar datos del perfil
    Route::put('customer/profile', [CustomerController::class, 'updateProfile']);


});

#endregion