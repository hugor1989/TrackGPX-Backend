<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class GeofenceController extends Controller
{
    /**
     * 📋 Listar geocercas de un dispositivo
     * GET /api/devices/{deviceId}/geofences
     */
    public function index($deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);
            
            
            $geofences = Geofence::where('device_id', $deviceId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'geofences' => $geofences
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener geocercas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ➕ Crear nueva geocerca
     * POST /api/devices/{deviceId}/geofences
     */
    public function store(Request $request, $deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);
            
            

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|in:circle,polygon',
                'icon' => 'nullable|string',
                'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                
                // Para círculos
                'center_lat' => 'required_if:type,circle|nullable|numeric|between:-90,90',
                'center_lon' => 'required_if:type,circle|nullable|numeric|between:-180,180',
                'radius' => 'required_if:type,circle|nullable|integer|min:50|max:50000',
                
                // Para polígonos
                'polygon_points' => 'required_if:type,polygon|nullable|array|min:3',
                'polygon_points.*.lat' => 'required|numeric|between:-90,90',
                'polygon_points.*.lon' => 'required|numeric|between:-180,180',
                
                // Alertas
                'alert_on_enter' => 'boolean',
                'alert_on_exit' => 'boolean',
                
                // Horario
                'schedule_enabled' => 'boolean',
                'schedule_days' => 'nullable|array',
                'schedule_start' => 'nullable|date_format:H:i',
                'schedule_end' => 'nullable|date_format:H:i',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $geofence = Geofence::create([
                'device_id' => $device->id,
                'customer_id' => $device->customer_id,
                'name' => $request->name,
                'type' => $request->type,
                'icon' => $request->icon ?? 'location',
                'color' => $request->color ?? '#007AFF',
                'center_lat' => $request->center_lat,
                'center_lon' => $request->center_lon,
                'radius' => $request->radius,
                'polygon_points' => $request->polygon_points,
                'alert_on_enter' => $request->alert_on_enter ?? true,
                'alert_on_exit' => $request->alert_on_exit ?? true,
                'schedule_enabled' => $request->schedule_enabled ?? false,
                'schedule_days' => $request->schedule_days,
                'schedule_start' => $request->schedule_start,
                'schedule_end' => $request->schedule_end,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Geocerca creada correctamente',
                'geofence' => $geofence
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear geocerca: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✏️ Actualizar geocerca
     * PUT /api/geofences/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $geofence = Geofence::findOrFail($id);
            
            // Verificar permisos
            if ($geofence->customer_id !== Auth::user()->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar esta geocerca'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'icon' => 'nullable|string',
                'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'alert_on_enter' => 'nullable|boolean',
                'alert_on_exit' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $geofence->update($request->only([
                'name',
                'icon',
                'color',
                'alert_on_enter',
                'alert_on_exit',
                'is_active',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Geocerca actualizada correctamente',
                'geofence' => $geofence
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar geocerca: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🗑️ Eliminar geocerca
     * DELETE /api/geofences/{id}
     */
    public function destroy($id)
    {
        try {
            $geofence = Geofence::findOrFail($id);
            
            // Verificar permisos
            if ($geofence->customer_id !== Auth::user()->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar esta geocerca'
                ], 403);
            }

            $geofence->delete();

            return response()->json([
                'success' => true,
                'message' => 'Geocerca eliminada correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar geocerca: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📍 Verificar si dispositivo está en geocercas
     * POST /api/devices/{deviceId}/check-geofences
     */
    public function checkGeofences(Request $request, $deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);

            $validator = Validator::make($request->all(), [
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 400);
            }

            $geofences = Geofence::where('device_id', $deviceId)
                ->where('is_active', true)
                ->get();

            $insideGeofences = [];

            foreach ($geofences as $geofence) {
                if ($geofence->containsPoint($request->latitude, $request->longitude)) {
                    $insideGeofences[] = $geofence;
                }
            }

            return response()->json([
                'success' => true,
                'inside_geofences' => $insideGeofences,
                'count' => count($insideGeofences)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar geocercas: ' . $e->getMessage()
            ], 500);
        }
    }
}