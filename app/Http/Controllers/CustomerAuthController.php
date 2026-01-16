<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;
use App\Models\VerificationCode;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\OpenPayService;
use App\Services\APICrmWhatSapp;


class CustomerAuthController extends AppBaseController
{

    protected $openPayService;
    //CRM WhatsApp
    protected $whatapiService;
    // Inyectar el servicio en el constructor
    public function __construct(
        OpenPayService $openPayService,
        APICrmWhatSapp $whatapiService,
    ) {
        $this->openPayService = $openPayService;
        $this->whatapiService = $whatapiService;
    }

    public function customerRegister(Request $request)
    {
        Log::info('📥 Iniciando registro de cliente', ['data' => $request->all()]);

        try {
            $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|string|email|max:255',
                'phone'    => 'nullable|string|max:20',
                'password' => 'required|string|min:6|confirmed',
            ]);
            Log::info('✔ Validación correcta');
        } catch (\Exception $e) {
            Log::error('❌ Error en validación', ['error' => $e->getMessage()]);
            return $this->respond(false, null, null, $e->getMessage(), 422);
        }

        // Verificar email repetido
        if (Customer::where('email', $request->email)->exists()) {
            Log::warning('⚠ Email ya registrado', ['email' => $request->email]);
            return $this->respond(false, null, null, 'El email ya está registrado', 409);
        }

        try {
            $customer = Customer::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'password' => Hash::make($request->password),
                'status'   => 'pending',
            ]);
            Log::info('✔ Cliente creado', ['customer_id' => $customer->id]);
        } catch (\Exception $e) {
            Log::error('❌ Error creando cliente', ['error' => $e->getMessage()]);
            return $this->respond(false, null, null, 'No se pudo crear el cliente', 500);
        }

        // Código OTP
        try {
            $verificationCode = $this->generateVerificationCode($customer);
            Log::info('✔ Código OTP generado', [
                'customer_id' => $customer->id,
                'otp_id' => $verificationCode->id,
                'otp_code' => $verificationCode->code
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error generando código OTP', ['error' => $e->getMessage()]);
            return $this->respond(false, null, null, 'No se pudo generar el código OTP', 500);
        }

        // Enviar WhatsApp
        try {
            Log::info('📤 Enviando WhatsApp', [
                'phone' => $request->phone,
                'message' => "You code OTP is: " . $verificationCode->code
            ]);

            $message = "You code OTP is: " . $verificationCode->code;
            $resultWhatsApp = $this->whatapiService->sendMessage($request->phone, $message);

            Log::info('📬 Resultado WhatsApp', ['response' => $resultWhatsApp]);
        } catch (\Exception $e) {
            Log::error('❌ Error enviando WhatsApp', ['error' => $e->getMessage()]);
            $resultWhatsApp = null;
        }

        return $this->respond(true, null, [
            'customer' => $customer,
            'verification_code_id' => $verificationCode->id,
            'result' => $resultWhatsApp
        ], 'Cliente registrado correctamente. Se ha enviado un código de verificación.', 201);
    }


    /**
     * Genera un código de verificación único
     */
    private function generateVerificationCode(Customer $customer): VerificationCode
    {
        $code = Str::random(5); // Código de 6 caracteres
        $expiresAt = now()->addMinutes(15); // Expira en 15 minutos

        // Asegurarse de que el código sea único
        while (VerificationCode::where('code', $code)->exists()) {
            $code = Str::random(5);
        }

        return VerificationCode::create([
            'customer_id' => $customer->id,
            'code' => $code,
            'expires_at' => $expiresAt,
            'is_used' => false
        ]);
    }

    /**
     * Envía el email de verificación
     */
    private function sendVerificationEmail(Customer $customer, string $code): void
    {
        try {
            Mail::to($customer->email)->send(new VerificationCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Error enviando email de verificación: ' . $e->getMessage());
            // No fallar el registro solo por error de email
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
            'code'  => 'required|string|size:5'
        ]);

        try {
            DB::beginTransaction();

            Log::info('🔍 Verificando OTP', [
                'email' => $request->email,
                'code'  => $request->code,
            ]);

            $customer = Customer::where('email', $request->email)->first();

            Log::info('👤 Customer encontrado', [
                'customer_id' => $customer?->id,
                'openpay_customer_id' => $customer?->openpay_customer_id,
            ]);

            if (!$customer) {
                return $this->respond(false, null, null, 'Cliente no encontrado', 404);
            }

            $verificationCode = VerificationCode::forCustomer($customer->id)
                ->where('code', $request->code)
                ->valid()
                ->first();

            Log::info('📩 Resultado búsqueda de VerificationCode', [
                'customer_id' => $customer->id,
                'code'        => $request->code,
                'verificationCode' => $verificationCode,
            ]);

            if (!$verificationCode) {
                return $this->respond(false, null, null, 'Código inválido o expirado', 400);
            }

            if (!$customer->openpay_customer_id) {
                $openpayCustomer = $this->openPayService->createCustomer([
                    'name'         => $customer->name,
                    'last_name'    => $customer->last_name,
                    'email'        => $customer->email,
                    'phone_number' => $customer->phone ?? '',
                ]);

                Log::info('✅ Cliente creado en OpenPay', [
                    'openpay_customer_id' => $openpayCustomer->id,
                ]);

                $customer->openpay_customer_id = $openpayCustomer->id;
            }

            $verificationCode->markAsUsed();

            $customer->update([
                'status' => 'active',
                'openpay_customer_id' => $customer->openpay_customer_id
            ]);

            $token = $customer->createToken('customer_token', ['customer'])->plainTextToken;

            DB::commit();

            Log::info('🎉 Verificación exitosa', [
                'customer_id' => $customer->id,
                'token' => $token,
            ]);

            return $this->respond(true, $token, [
                'customer'             => $customer,
                'verified'             => true,
                'openpay_customer_id'  => $customer->openpay_customer_id,
            ], 'Cuenta verificada correctamente', 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('❌ Error en verifyOtp', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->respond(false, null, null, 'Error en verificación: ' . $e->getMessage(), 500);
        }
    }

    public function customerLoginNuevo(Request $request)
    {
        // 1. Validación corregida
        // Limpiamos espacios en blanco por si acaso
        $phone = trim($request->input('phone'));
        $password = $request->input('password');

        // 2. Buscar al cliente
        $customer = Customer::where('phone', $phone)->first();

        if (!$customer || !Hash::check($password, $customer->password)) {
            return $this->error('Credenciales incorrectas', 401);
        }

        // 3. Carga de relaciones (Tu lógica actual corregida)
        $columnsToSelect = ['id', 'imei', 'customer_id', 'last_latitude', 'last_longitude', 'last_speed', 'last_heading', 'last_connection'];

        if ($customer->role === 'admin') {
            $customer->load([
                'devices' => function ($query) use ($columnsToSelect) {
                    $query->select($columnsToSelect);
                },
                'devices.configuration',
                'devices.vehicle'
            ]);
        } else {
            $customer->load([
                'sharedDevices' => function ($query) use ($columnsToSelect) {
                    $query->select($columnsToSelect);
                },
                'sharedDevices.configuration',
                'sharedDevices.vehicle'
            ]);
        }

        $token = $customer
            ->createToken('customer_token', ['customer'])
            ->plainTextToken;

        return $this->respond(true, $token, [
            'customer' => $customer
        ], 'Login de cliente correcto', 200);
    }


    /*  public function customerLoginNuevo(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        // Buscamos al cliente junto con sus devices (solo id e imei)
        $customer = Customer::with(['devices:id,imei,customer_id'])
            ->where('email', $request->email)
            ->first();
        Log::info('Devices del customer', ['devices' => $customer]);

        if (!$customer || !Hash::check($request->password, $customer->password)) {
            return $this->error('Credenciales incorrectas', 401);
        }

        $token = $customer->createToken('customer_token', ['customer'])->plainTextToken;
        Log::info('Devices del customer', ['devices' => $customer->devices]);

       // return $this->respond(true, $token, $customer, 'Login de cliente correcto');

        return $this->respond(true, $token, [
           'customer' => $customer,
        ], 'Login de cliente correcto', 200);
    } */

    public function customerLogout(Request $request)
    {
        $customer = $request->user();

        if (!$customer) {
            return $this->error('Cliente no autenticado.', 401);
        }

        $customer->currentAccessToken()->delete();

        return $this->success(null, 'Sesión cerrada correctamente.');
    }

    /**
     * 1. SOLICITAR RECUPERACIÓN: Genera OTP y lo envía por WhatsApp
     */
    /**
     * Envía OTP de recuperación buscando por TELÉFONO
     */
    public function sendRecoveryOtp(Request $request)
    {
        // 1. Validar que envíen el teléfono y que exista en la tabla customers
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|exists:customers,phone',
        ]);

        if ($validator->fails()) {
            // Si el teléfono no existe, devolvemos error 404 o 422
            return $this->respond(false, null, null, 'El número de teléfono no está registrado.', 404);
        }

        try {
            // 2. Buscar al cliente por teléfono
            $customer = Customer::where('phone', $request->phone)->first();

            // 3. Generar el código OTP
            $verificationCode = $this->generateVerificationCode($customer);

            Log::info('🔑 Recovery OTP generado (Phone)', [
                'customer_id' => $customer->id,
                'phone' => $customer->phone,
                'otp_code' => $verificationCode->code
            ]);

            // 4. Enviar mensaje por WhatsApp
            $message = "Hola {$customer->name}, tu código de recuperación es: " . $verificationCode->code;

            try {
                $this->whatapiService->sendMessage($customer->phone, $message);
                Log::info('📤 WhatsApp enviado', ['phone' => $customer->phone]);
            } catch (\Exception $e) {
                Log::error('❌ Error API WhatsApp', ['error' => $e->getMessage()]);
                return $this->respond(false, null, null, 'Error al enviar el mensaje. Intente más tarde.', 500);
            }

            return $this->respond(true, null, [
                'phone' => $customer->phone
            ], 'Código enviado correctamente a tu WhatsApp.', 200);
        } catch (\Exception $e) {
            Log::error('❌ Error en sendRecoveryOtp', ['error' => $e->getMessage()]);
            return $this->respond(false, null, null, 'Ocurrió un error inesperado.', 500);
        }
    }

    /**
     * 2. CAMBIAR CONTRASEÑA: Valida el OTP y actualiza el password
     */
    public function resetPassword(Request $request)
    {
        // 1. Validar teléfono, código y password
        $validator = Validator::make($request->all(), [
            'phone'    => 'required|string|exists:customers,phone',
            'code'     => 'required|string|size:5', // Asumiendo que tu código es de 5 dígitos como en generateVerificationCode
            'password' => 'required|string|min:6|confirmed', // 'confirmed' espera field 'password_confirmation'
        ]);

        if ($validator->fails()) {
            return $this->respond(false, null, null, $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();

            // 2. Buscar cliente por teléfono
            $customer = Customer::where('phone', $request->phone)->first();

            // 3. Verificar código válido para ese cliente
            $verificationCode = VerificationCode::forCustomer($customer->id)
                ->where('code', $request->code)
                ->valid()
                ->first();

            if (!$verificationCode) {
                return $this->respond(false, null, null, 'El código es inválido o ha expirado.', 400);
            }

            // 4. Actualizar contraseña
            $customer->password = Hash::make($request->password);
            $customer->save();

            // 5. Marcar código como usado y limpiar tokens
            $verificationCode->markAsUsed();
            $customer->tokens()->delete(); // Cerrar sesiones anteriores por seguridad

            DB::commit();
            Log::info('✅ Contraseña actualizada vía Phone', ['customer_id' => $customer->id]);

            return $this->respond(true, null, null, 'Contraseña actualizada correctamente.', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error en resetPassword', ['error' => $e->getMessage()]);
            return $this->respond(false, null, null, 'Error al actualizar la contraseña.', 500);
        }
    }
}
