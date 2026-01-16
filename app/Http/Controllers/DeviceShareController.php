<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceShare;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DeviceShareController extends Controller
{
    // 1. GENERAR EL ENLACE (Esto lo llama tu App cuando el usuario elige "Compartir 1 hora")
    public function createShareLink(Request $request)
    {
        $request->validate([
            'device_id' => 'required|exists:devices,id',
            'duration_minutes' => 'required|integer|min:15|max:1440', // Ej: entre 15 min y 24 horas
        ]);
        
        // Validar que el usuario sea dueño del device (Tu lógica de permisos aquí)
        // $user = auth()->user(); ...

        $token = Str::random(40); // Generamos un código único

        $share = DeviceShare::create([
            'device_id' => $request->device_id,
            'token' => $token,
            'expires_at' => Carbon::now()->addMinutes($request->duration_minutes),
        ]);

        // Retornamos la URL que el usuario compartirá por WhatsApp/SMS
        // Supongamos que tienes un frontend web en 'tudominio.com/track/view/{token}'
        $shareUrl = "https://tudominio.com/live-track/" . $token;

        return response()->json([
            'success' => true,
            'url' => $shareUrl,
            'expires_at' => $share->expires_at,
            'token' => $token
        ]);
    }

    // 2. CONSULTAR UBICACIÓN (PÚBLICO)
    // Esta ruta la consumirá el mapa web cada 10 segundos
    public function getSharedLocation($token)
    {
        $share = DeviceShare::where('token', $token)->first();

        // Validaciones
        if (!$share) {
            return response()->json(['error' => 'Enlace inválido'], 404);
        }

        if (!$share->isValid()) {
            return response()->json(['error' => 'El enlace ha expirado'], 410); // 410 Gone
        }

        // Obtener la última ubicación del dispositivo
        $device = $share->device;
        
        // Solo enviamos datos necesarios, NO enviamos datos sensibles del cliente
        return response()->json([
            'success' => true,
            'data' => [
                'lat' => $device->last_latitude,
                'lng' => $device->last_longitude,
                'speed' => $device->last_speed,
                'battery' => $device->battery_level, // O de donde saques la batería actual
                'last_update' => $device->last_connection,
                'alias' => $device->vehicle ? $device->vehicle->alias : 'Vehículo',
                // Calculamos tiempo restante para que el frontend muestre "Termina en 10 min"
                'expires_in_seconds' => now()->diffInSeconds($share->expires_at, false)
            ]
        ]);
    }
}