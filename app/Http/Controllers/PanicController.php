<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Device;
use App\Models\EmergencyContact;
use App\Events\PanicAlertTriggered;
use Illuminate\Support\Facades\Log;

class PanicController extends Controller
{
    public function trigger(Request $request)
    {
        $request->validate([
            'imei' => 'required|exists:devices,imei',
            'lat' => 'required',
            'lon' => 'required',
        ]);

        try {
            $device = Device::where('imei', $request->imei)->first();
            
            // Validar dueño
            $owner = $device->customer; 
            if (!$owner) {
                return response()->json(['success' => false, 'message' => 'Dispositivo sin dueño'], 404);
            }

            // Obtener contactos de emergencia
            $contacts = EmergencyContact::where('customer_id', $owner->id)->get();

            // Preparar datos de ubicación
            $locationData = [
                'lat' => $request->lat,
                'lon' => $request->lon,
                'timestamp' => now()->toIso8601String()
            ];

            // 🔥 DISPARAR EL EVENTO (Esto enviará los WhatsApps en segundo plano)
            PanicAlertTriggered::dispatch($device, $locationData, $contacts);

            return response()->json([
                'success' => true, 
                'message' => "Alerta de pánico recibida. Procesando notificaciones."
            ]);

        } catch (\Exception $e) {
            Log::error("Error Panic Controller: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
