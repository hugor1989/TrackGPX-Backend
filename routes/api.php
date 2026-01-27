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
use App\Http\Controllers\DeviceConfigurationController;
use App\Http\Controllers\GeofenceController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\DeviceShareController;
use App\Http\Controllers\EmergencyContactController;
use App\Http\Controllers\PanicController;
use App\Http\Controllers\TaxInformationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::options('{any}', function () {
    return response()->json([], 204);
})->where('any', '.*');

Route::get('/debug/auth', function (Request $request) {
    Log::info('🔐 Debug auth endpoint', [
        'user' => $request->user() ? [
            'id' => $request->user()->id,
            'email' => $request->user()->email,
            'type' => get_class($request->user())
        ] : null,
        'headers' => $request->headers->all(),
        'bearer_token' => $request->bearerToken(),
        'middleware' => $request->route()?->gatherMiddleware(),
    ]);

    return response()->json([
        'authenticated' => $request->user() ? true : false,
        'user' => $request->user(),
        'token_present' => $request->bearerToken() ? true : false,
    ]);
})->middleware('auth:sanctum'); // Probar con sanctum
#region Webhook
Route::post('/webhooks/openpay', [WebhookController::class, 'handleOpenPayWebhook'])
    ->name('webhooks.openpay');
#endregion

#region Metodos para User

Route::post('user/login-user', [AuthController::class, 'login']);
Route::post('user/register-user', [AuthController::class, 'registerUser']);


Route::middleware('auth:user')->group(function () {

    Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);

    Route::post('user/session-close', [AuthController::class, 'userLogout']);
    Route::get('user/profile', [UserController::class, 'profile']); // ✅ perfil user

    Route::get('get-all-customers', [CustomerController::class, 'GetAllCustomers']);
    Route::get('customers-by-id/{id}', [CustomerController::class, 'GetCustomerById']);
    Route::delete('customers-delete/{id}', [CustomerController::class, 'Customerdestroy']);
    Route::patch('customers-inactive/{id}', [CustomerController::class, 'toggleActiveCustomer']);

    Route::get('get-all-payments', [PaymentController::class, 'index']);

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

Route::post('customer/recovery/send', [CustomerAuthController::class, 'sendRecoveryOtp']);
Route::post('customer/recovery/reset', [CustomerAuthController::class, 'resetPassword']);

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
    Route::post('customer/push-token', [PushTokenController::class, 'store']);


    //Activate GPS
    Route::post('devices/activate-gps', [DeviceController::class, 'activateFromApp']);

    //Obtener los Devices en la App ligados al customer
    Route::get('devices/customer/{Id}', [DeviceController::class, 'getDevicesByCustomer']);

    //Obtener dispositivos cercanos
    Route::get('devices/nearby/{imei}', [DeviceController::class, 'getNearbyDevices']);

    //Device Configuration
    Route::get('devices-get/{device}/configuration', [DeviceConfigurationController::class, 'show']);
    Route::put('devices-update/{device}/configuration', [DeviceConfigurationController::class, 'update']);
    Route::post('devices-upload/{device}/configuration/image', [DeviceConfigurationController::class, 'uploadImage']);


    // 🔔 Configuración de alarmas
    Route::post('devices/{id}/alarms', [DeviceController::class, 'updateAlarms']);
    Route::get('devices/{id}/alarms', [DeviceController::class, 'getAlarms']);

    // 📤 Comandos al dispositivo
    Route::post('devices/{id}/commands', [DeviceController::class, 'sendCommand']);
    Route::get('devices/{id}/commands', [DeviceController::class, 'getCommands']);
    Route::get('devices/imei/{imei}/id', [DeviceController::class, 'getIdByImei']);

    // Geocercas
    Route::get('devices/{deviceId}/geofences', [GeofenceController::class, 'index']);
    Route::post('devices/{deviceId}/geofences', [GeofenceController::class, 'store']);
    Route::put('geofences/{id}', [GeofenceController::class, 'update']);
    Route::delete('geofences/{id}', [GeofenceController::class, 'destroy']);
    Route::post('devices/{deviceId}/check-geofences', [GeofenceController::class, 'checkGeofences']);

    // 1. Rutas disponibles para un dispositivo
    // GET: /api/devices/{deviceId}/routes/available
    Route::get('devices/{deviceId}/routes/available', [RouteController::class, 'getDeviceRoutes']);

    // 2. Resumen de rutas por días  
    // GET: /api/devices/{deviceId}/routes/summary
    Route::get('devices/{deviceId}/routes/summary', [RouteController::class, 'getRoutesSummary']);

    // 3. Obtener ruta por fechas (CON parámetros GET)
    // GET: /api/devices/{deviceId}/route?start_date=...&end_date=...
    Route::post('devices/{deviceId}/route', [RouteController::class, 'getRouteByDate']);

    Route::get('devices/{device}/activity', [RouteController::class, 'getActivityByDay']);

    //Reportes
    Route::get('devices/{deviceId}/reports/alarms', [RouteController::class, 'getAlarmsReport']);
    Route::get('devices/{deviceId}/reports/daily-activity', [RouteController::class, 'getDailyActivityReport']);
    Route::get('devices/{deviceId}/export-activity', [RouteController::class, 'exportActivityReport']);
    Route::get('devices/{deviceId}/export-alarms', [RouteController::class, 'exportAlarmsReport']);
    Route::get('devices/{deviceId}/export-viajes', [RouteController::class, 'exportTripsReport']);
    Route::get('devices/{deviceId}/reports/stops', [RouteController::class, 'getStopsReport']);

    // 4. Exportar ruta
    // POST: /api/devices/{deviceId}/route/export
    Route::post('devices/{deviceId}/route/export', [RouteController::class, 'exportRoute']);

    // Obtener dispositivos del customer autenticado (admin o member)
    Route::get('devices/my', [DeviceController::class, 'getMyDevices']);
    // Miembros
    Route::post('members', [MemberController::class, 'store']);              // crear miembro
    Route::get('members', [MemberController::class, 'index']);               // listar miembros
    Route::post('members/{member}/devices', [MemberController::class, 'assignDevices']); // asignar devices
    Route::get('members/{member}/devices', [MemberController::class, 'devices']);
    Route::post('members/{member}/invite-whatsapp', [MemberController::class, 'inviteWhatsapp']);

    Route::prefix('notifications')->group(function () {
        Route::get('/get-all', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/delete/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/delete-all', [NotificationController::class, 'destroyAll']);
    });

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/statistics', [DashboardController::class, 'getStatistics']);
        Route::get('/devices', [DashboardController::class, 'getDevices']);
        Route::get('/device/{deviceId}/details', [DashboardController::class, 'getDeviceDetails']);
    });

    Route::prefix('contacts')->group(function () {
        Route::get('users/{userId}/emergency-contacts', [EmergencyContactController::class, 'index']);
        Route::post('users/{userId}/emergency-contacts', [EmergencyContactController::class, 'store']);
        Route::put('emergency-contacts/{id}', [EmergencyContactController::class, 'update']);
        Route::delete('emergency-contacts/{id}', [EmergencyContactController::class, 'destroy']);
    });


    Route::post('panic', [PanicController::class, 'trigger']);
    //Crear enlace de compartición
    Route::post('device/create-share-link', [DeviceShareController::class, 'createShareLink']);

    //Facturacion
    Route::get('tax-info/get-data', [TaxInformationController::class, 'show']);
    Route::post('tax-info/store', [TaxInformationController::class, 'store']);
});

#endregion

#region Endpoint para solo registro de device desde tco
Route::post('auto/devices/auto-register', [DeviceController::class, 'autoRegisterDevices']);
Route::post('auto/locations/insert', [LocationController::class, 'createInsertLocations']);
Route::get('devices/imei/{imei}/id', [DeviceController::class, 'getIdByImei']);
Route::get('devices/{id}/alarms', [DeviceController::class, 'getAlarms']);

// Ruta PÚBLICA (sin auth) para LEER la ubicación usando el token
Route::get('share/view/{token}', [DeviceShareController::class, 'getSharedLocation']);

#endregion