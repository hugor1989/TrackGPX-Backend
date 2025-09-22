<?php

use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\CardController;



Route::options('{any}', function () {
    return response()->json([], 204);
})->where('any', '.*');


#region Webhook
Route::post('/webhooks/openpay', [WebhookController::class, 'handleOpenPayWebhook'])
    ->name('webhooks.openpay');
#endregion

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
    Route::delete('delete-plan-id/{id}', [PlanController::class, 'PlanDeleteById']);

});

#endregion


#region Metodos para Customer
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
    Route::post('subscription/create-subscription', [SubscriptionController::class, 'createPending']);
    Route::get('subscription/get-subscriptions', [SubscriptionController::class, 'getUserSubscriptions']);
    Route::post('subscription/process-payment', [SubscriptionController::class, 'pay']);


    //Actualizar datos del perfil
    Route::put('customer/profile', [CustomerController::class, 'updateProfile']);

    // Tarjetas
    Route::post('customer/add-cards', [CardController::class, 'addCard']);
    Route::get('customer/get-cards', [CardController::class, 'getCards']);
    Route::get('customer/get-card-Id/{cardId}', [CardController::class, 'getCard']);
    Route::delete('customer/delete-cards/{cardId}', [CardController::class, 'deleteCard']);


});

#endregion