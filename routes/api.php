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
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SimCardController;




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

    Route::get('admin/subscriptions', [SubscriptionController::class, 'getAdminSubscriptions']);
    Route::get('admin/subscriptions/stats', [SubscriptionController::class, 'getSubscriptionStats']);

    //Devices
    Route::get('devices/get-all-devices', [DeviceController::class, 'getAllDevices']);
    Route::post('devices/create-device', [DeviceController::class, 'createDevice']);
    Route::get('devices/statistics', [DeviceController::class, 'statistics']);
    Route::post('devices/import', [DeviceController::class, 'import']);
    Route::get('devices/get-by-id/{id}', [DeviceController::class, 'getDevicebyId']);
    Route::put('devices/update-data-by-Id/{id}', [DeviceController::class, 'updateDevice']);
    Route::delete('devices/delete-byId{id}', [DeviceController::class, 'deletebyId']);
    Route::post('devices/generate-activation-code/{id}', [DeviceController::class, 'generateActivationCodeDevice']);

    //Sim Cards
    Route::get('sim-cards/getAll-simCards', [SimCardController::class, 'getAllSimCard']);
    Route::get('sim-cards/get-by-id/{id}', [SimCardController::class, 'Get_Simcard_ById']);
    Route::post('sim-cards/create-cardsim', [SimCardController::class, 'create_SimCard']);
    Route::put('sim-cards/update-date/{id}', [SimCardController::class, 'updateSimCard']);
    Route::delete('sim-cards/delete-by-id/{id}', [SimCardController::class, 'destroy']);
    Route::post('sim-cards/import-provider', [SimCardController::class, 'importFromProvider']);
    Route::get('sim-cards/available', [SimCardController::class, 'getAvailableSims']);

});

#endregion


#region Metodos para Customer
Route::post('CustomerUser/register', [CustomerAuthController::class, 'customerRegister']);
Route::post('customer/login', [CustomerAuthController::class, 'customerLoginNuevo']);
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

    //Activate GPS
    Route::post('devices/activate-gps', [DeviceController::class, 'activateFromApp']);

    //Obtener los Devices en la App ligados al customer
    Route::get('devices/customer/{Id}', [DeviceController::class, 'getDevicesByCustomer']);

    //Obtener dispositivos cercanos
    Route::get('devices/nearby/{imei}', [DeviceController::class, 'getNearbyDevices']);


});

#endregion

#region Endpoint para solo registro de device desde tco
Route::post('auto/devices/auto-register', [DeviceController::class, 'autoRegisterDevices']);
Route::post('auto/locations/insert', [LocationController::class, 'createInsertLocations']);

#endregion