<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Store a newly created location
     */
    public function createInsertLocations(Request $request): JsonResponse
    {
        try {
            // Validar los datos recibidos
            $validated = $request->validate([
                'imei' => 'required|string|max:255',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'speed' => 'nullable|numeric',
                'battery_level' => 'nullable|numeric',
                'altitude' => 'nullable|numeric',
                'timestamp' => 'nullable|date'
            ]);

            // Buscar el dispositivo por IMEI
            $device = Device::where('imei', $validated['imei'])->first();

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            // Manejar el formato del timestamp
            $timestamp = $validated['timestamp'] ?? now();

            // Si es string, convertir a formato MySQL
            if (is_string($timestamp)) {
                $timestamp = date('Y-m-d H:i:s', strtotime($timestamp));
            } else if ($timestamp instanceof \Carbon\Carbon) {
                $timestamp = $timestamp->format('Y-m-d H:i:s');
            }

            // Crear la nueva ubicación
            $location = Location::create([
                'device_id' => $device->id,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'speed' => $validated['speed'] ?? null,
                'battery_level' => $validated['battery_level'] ?? null,
                'altitude' => $validated['altitude'] ?? null,
                'timestamp' => $timestamp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ubicación guardada correctamente',
                'data' => $location
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener ubicaciones por IMEI del dispositivo
     */
    public function getByImei($imei): JsonResponse
    {
        try {
            $device = Device::where('emei', $imei)->first();

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            $locations = Location::where('device_id', $device->id)
                ->orderBy('timestamp', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $locations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ubicaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
