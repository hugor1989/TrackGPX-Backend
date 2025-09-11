<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Customer;

class CustomerAuthController extends AppBaseController
{

    public function customerRegister(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:customers,email',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $customer = Customer::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password),
            'status'   => 'active',
        ]);

        //$token = $customer->createToken('customer_token', ['customer'])->plainTextToken;

        return $this->respond(true, null, $customer, 'Cliente registrado correctamente', 201);
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