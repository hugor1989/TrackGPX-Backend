<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class AuthController extends AppBaseController
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->error('Credenciales incorrectas', 401);
        }

        $token = $user->createToken('user_token', ['user'])->plainTextToken;

        return $this->respond(true, $token, $user, 'Login correcto');
    }

    public function userLogout(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Usuario no autenticado.', 401);
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete(); // Revoca solo el token actual
        }

        return $this->success(null, 'Sesión cerrada correctamente.');
    }

    public function registerUser(Request $request)
    {
        // Validar entrada
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            // Se requiere password_confirmation también
        ]);

        if ($validator->fails()) {
            return $this->error('Errores de validación', 422, $validator->errors());
        }

        // Crear usuario
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'active'   => true,
        ]);

        // Generar token de acceso
        //$token = $user->createToken('auth_token')->plainTextToken;

        return $this->respond(true, null, $user, 'Usuario creado correctamente', 201);
    }
}
