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
    public function __construct(OpenPayService $openPayService,
                                APICrmWhatSapp $whatapiService,)
    {
        $this->openPayService = $openPayService;
        $this->whatapiService = $whatapiService;

    }
    public function customerRegister(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // Verificación adicional por si acaso
        if (Customer::where('email', $request->email)->exists()) {
            return $this->respond(false, null, null, 'El email ya está registrado', 409);
        }

        // Crear el cliente con estado "pending"
        $customer = Customer::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password),
            'status'   => 'pending', // Cambiado a pending hasta verificación
        ]);

        // Generar y guardar código OTP
        $verificationCode = $this->generateVerificationCode($customer);

        // Enviar email con el código
        // Enviar el código por WhatsApp (simulado)
        $message = "You code OTP is: " . $verificationCode->code;
        $resultWhatsApp = $this->whatapiService->sendMessage($request->phone, $message);
        
        //$this->sendVerificationEmail($customer, $verificationCode->code);

        return $this->respond(true, null, [
            'customer' => $customer,
            'verification_code_id' => $verificationCode->id,
            'result' => $resultWhatsApp,
            'message' => 'Revisa tu email para el código de verificación'
        ], 'Cliente registrado correctamente. Se ha enviado un código de verificación a su email.', 201);
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
    }



  /*   public function customerLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer || !Hash::check($request->password, $customer->password)) {
            return $this->error('Credenciales incorrectas', 401);
        }

        $token = $customer->createToken('customer_token', ['customer'])->plainTextToken;

        return $this->respond(true, $token, $customer, 'Login de cliente correcto');
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
}
