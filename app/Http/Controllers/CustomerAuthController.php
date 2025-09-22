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


class CustomerAuthController extends AppBaseController
{

     protected $openPayService;

    // Inyectar el servicio en el constructor
    public function __construct(OpenPayService $openPayService)
    {
        $this->openPayService = $openPayService;
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
        $this->sendVerificationEmail($customer, $verificationCode->code);

        return $this->respond(true, null, [
            'customer' => $customer,
            'verification_code_id' => $verificationCode->id,
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

            $customer = Customer::where('email', $request->email)->first();

            if (!$customer) {
                return $this->respond(false, null, null, 'Cliente no encontrado', 404);
            }

            // Buscar código válido usando el scope del modelo
            $verificationCode = VerificationCode::forCustomer($customer->id)
                ->where('code', $request->code)
                ->valid()
                ->first();

            if (!$verificationCode) {
                return $this->respond(false, null, null, 'Código inválido o expirado', 400);
            }

            // ✅ Crear customer en OpenPay (solo si no existe)
            if (!$customer->openpay_customer_id) {
                $openpayCustomer = $this->openPayService->createCustomer([
                    'name'         => $customer->name,
                    'last_name'    => $customer->last_name,
                    'email'        => $customer->email,
                    'phone_number' => $customer->phone ?? '',
                ]);

                $customer->openpay_customer_id = $openpayCustomer->id;
            }

            // Marcar código como usado
            $verificationCode->markAsUsed();

            // Activar el cliente y marcar email como verificado
            $customer->update([
                'status' => 'active',
                'openpay_customer_id' => $customer->openpay_customer_id
            ]);

            // Generar token de acceso
            $token = $customer->createToken('customer_token', ['customer'])->plainTextToken;

            DB::commit();

            return $this->respond(true, $token, [
                'customer'             => $customer,
                'verified'             => true,
                'openpay_customer_id'  => $customer->openpay_customer_id,
            ], 'Cuenta verificada correctamente', 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return $this->respond(false, null, null, 'Error en verificación: ' . $e->getMessage(), 500);
        }
    }

   
    public function verifyOtpN(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
            'code' => 'required|string|size:5'
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return $this->respond(false, null, null, 'Cliente no encontrado', 404);
        }

        // Buscar código válido usando el scope del modelo
        $verificationCode = VerificationCode::forCustomer($customer->id)
            ->where('code', $request->code)
            ->valid()
            ->first();

        if (!$verificationCode) {
            return $this->respond(false, null, null, 'Código inválido o expirado', 400);
        }

        // Marcar código como usado
        $verificationCode->markAsUsed();

        // Activar el cliente
        $customer->update(['status' => 'active']);

        // Generar token de acceso
        $token = $customer->createToken('customer_token', ['customer'])->plainTextToken;

        return $this->respond(true, $token, [
            'customer' => $customer,
            'verified' => true
        ], 'Cuenta verificada correctamente', 200);
    }


    public function customerLogin(Request $request)
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
    }

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
