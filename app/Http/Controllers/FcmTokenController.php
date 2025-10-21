<?php

namespace App\Http\Controllers;

use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FcmTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'device_name' => 'nullable|string'
        ]);

        $user = $request->user();

        // Eliminar token si ya existe para otro usuario o dispositivo
        FcmToken::where('token', $request->token)
            ->orWhere(function ($query) use ($user, $request) {
                $query->where('user_id', $user->id)
                    ->where('device_name', $request->device_name);
            })
            ->delete();

        // Crear nuevo token
        $fcmToken = FcmToken::create([
            'user_id' => $user->id,
            'token' => $request->token,
            'device_name' => $request->device_name
        ]);

        return response()->json([
            'message' => 'Token registrado exitosamente',
            'token' => $fcmToken
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = $request->user();

        FcmToken::where('user_id', $user->id)
            ->where('token', $request->token)
            ->delete();

        return response()->json([
            'message' => 'Token eliminado exitosamente'
        ]);
    }
}