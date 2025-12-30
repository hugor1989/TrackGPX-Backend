<?php

namespace App\Http\Controllers;

use App\Events\DeviceRemovalAlert;
use App\Events\LowBatteryAlert;
use App\Events\SpeedAlertTriggered;
use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
                'timestamp' => 'nullable|date',
                'heading' => 'nullable|numeric',
                'satellites' => 'nullable|integer',
                'removal_detected' => 'nullable|boolean',
                'vibration_detected' => 'nullable|boolean',
            ]);

            Log::info('📍 Datos recibidos del GPS', [
                'imei' => $validated['imei'],
                'speed' => $validated['speed'] ?? 'null',
                'battery_level' => $validated['battery_level'] ?? 'null',
            ]);

            // Buscar el dispositivo por IMEI con relaciones necesarias
            $device = Device::where('imei', $validated['imei'])
                ->with(['customer', 'vehicle', 'alarms'])
                ->first();

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no encontrado'
                ], 404);
            }

            Log::info('📱 Dispositivo encontrado', [
                'device_id' => $device->id,
                'customer_id' => $device->customer_id,
                'status' => $device->status,
            ]);

            // Validar que el dispositivo esté activo
            if ($device->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no está activo. Status actual: ' . $device->status
                ], 403);
            }

            // Validar que el dispositivo tenga un cliente asignado
            if (empty($device->customer_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo no tiene un cliente asignado'
                ], 403);
            }

            // Procesar el timestamp
            $timestamp = $validated['timestamp'] ?? null;
            if ($timestamp) {
                $timestamp = Carbon::parse($timestamp)->setTimezone('America/Mexico_City');
            } else {
                $timestamp = Carbon::now('America/Mexico_City');
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

            Log::info('✅ Ubicación guardada', ['location_id' => $location->id]);

            // Preparar datos de ubicación para las alertas
            $locationData = [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'speed' => $validated['speed'] ?? 0,
                'battery_level' => $validated['battery_level'] ?? null,
                'altitude' => $validated['altitude'] ?? null,
                'timestamp' => $timestamp->toIso8601String(),
            ];

            // ============================================
            // VERIFICAR Y DISPARAR ALERTAS
            // ============================================
            
            $alarms = $device->alarms;

            Log::info('🔍 Verificando condiciones para alertas', [
                'device_id' => $device->id,
                'alarms_exist' => $alarms !== null,
                'customer_exists' => $device->customer !== null,
                'customer_id' => $device->customer_id,
                'expo_push_token' => $device->customer?->expo_push_token ?? 'NULL',
                'has_token' => !empty($device->customer?->expo_push_token),
            ]);

            if ($alarms && $device->customer && $device->customer->expo_push_token) {
                
                Log::info('✅ Condiciones cumplidas - Verificando cada tipo de alerta');
                
                // 1. ALERTA DE EXCESO DE VELOCIDAD
                Log::info('🚗 Verificando alerta de velocidad', [
                    'alarm_speed' => $alarms->alarm_speed,
                    'speed_limit' => $alarms->speed_limit,
                    'current_speed' => $validated['speed'] ?? 'null',
                ]);

                if ($alarms->alarm_speed && $alarms->speed_limit && isset($validated['speed'])) {
                    
                    Log::info('🔍 Comparando velocidades', [
                        'current_speed' => $validated['speed'],
                        'speed_limit' => $alarms->speed_limit,
                        'exceeds' => $validated['speed'] > $alarms->speed_limit,
                    ]);
                    
                    if ($validated['speed'] > $alarms->speed_limit) {
                        if ($this->shouldSendSpeedAlert($device)) {
                            Log::info('🚨 DISPARANDO alerta de velocidad', [
                                'device_id' => $device->id,
                                'imei' => $device->imei,
                                'current_speed' => $validated['speed'],
                                'speed_limit' => $alarms->speed_limit,
                            ]);

                            SpeedAlertTriggered::dispatch(
                                $device,
                                (float) $validated['speed'],
                                (float) $alarms->speed_limit,
                                $locationData
                            );
                            
                            $this->markSpeedAlertSent($device);
                        } else {
                            Log::info('⏰ Alerta de velocidad en cooldown', [
                                'device_id' => $device->id,
                            ]);
                        }
                    } else {
                        Log::info('✓ Velocidad dentro del límite - No se dispara alerta');
                        $this->clearSpeedAlertCache($device);
                    }
                } else {
                    Log::warning('⚠️ Alerta de velocidad NO configurada correctamente', [
                        'alarm_speed' => $alarms->alarm_speed ?? 'null',
                        'speed_limit' => $alarms->speed_limit ?? 'null',
                        'speed_received' => $validated['speed'] ?? 'null',
                    ]);
                }

                // 2. ALERTA DE BATERÍA BAJA (≤ 20%)
                if ($alarms->alarm_low_battery && isset($validated['battery_level'])) {
                    if ($validated['battery_level'] <= 20) {
                        if ($this->shouldSendBatteryAlert($device, $validated['battery_level'])) {
                            Log::info('🔋 DISPARANDO alerta de batería baja', [
                                'device_id' => $device->id,
                                'battery_level' => $validated['battery_level'],
                            ]);

                            LowBatteryAlert::dispatch(
                                $device,
                                (int) $validated['battery_level'],
                                $locationData
                            );
                            
                            $this->markBatteryAlertSent($device, $validated['battery_level']);
                        }
                    }
                }

                // 3. ALERTA DE REMOCIÓN DEL DISPOSITIVO
                if ($alarms->alarm_removal && ($validated['removal_detected'] ?? false)) {
                    if ($this->shouldSendRemovalAlert($device)) {
                        Log::info('🚨 DISPARANDO alerta de remoción', [
                            'device_id' => $device->id,
                        ]);

                        DeviceRemovalAlert::dispatch($device, $locationData);
                        $this->markRemovalAlertSent($device);
                    }
                }

                // 4. ALERTA DE VIBRACIÓN
                if ($alarms->alarm_vibration && ($validated['vibration_detected'] ?? false)) {
                    if ($this->shouldSendVibrationAlert($device)) {
                        Log::info('⚡ DISPARANDO alerta de vibración', [
                            'device_id' => $device->id,
                        ]);
                    }
                }

                // 5. ALERTA DE GEOCERCA
                if ($alarms->alarm_geofence) {
                    $this->checkGeofenceAlert($device, $validated['latitude'], $validated['longitude'], $locationData);
                }
            } else {
                Log::warning('❌ NO se pueden verificar alertas - Faltan condiciones', [
                    'alarms_exist' => $alarms !== null,
                    'customer_exists' => $device->customer !== null,
                    'has_token' => !empty($device->customer?->expo_push_token),
                    'expo_push_token' => $device->customer?->expo_push_token ?? 'NULL',
                ]);
                
                // Detalle de qué falta
                if (!$alarms) {
                    Log::error('❌ No hay configuración de alarmas para el dispositivo');
                }
                if (!$device->customer) {
                    Log::error('❌ El dispositivo no tiene cliente asignado');
                }
                if ($device->customer && !$device->customer->expo_push_token) {
                    Log::error('❌ El cliente no tiene expo_push_token configurado');
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Ubicación guardada correctamente',
                'data' => [
                    'location_id' => $location->id,
                    'device_id' => $device->id,
                    'timestamp' => $timestamp->toIso8601String(),
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('❌ Error en createInsertLocations', [
                'imei' => $request->imei ?? 'N/A',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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
            $device = Device::where('imei', $imei)->first();

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

    // ============================================
    // MÉTODOS PRIVADOS PARA CONTROL DE ALERTAS
    // ============================================

    private function shouldSendSpeedAlert(Device $device): bool
    {
        $cacheKey = "speed_alert_sent_{$device->id}";
        return !Cache::has($cacheKey);
    }

    private function markSpeedAlertSent(Device $device): void
    {
        $cacheKey = "speed_alert_sent_{$device->id}";
        Cache::put($cacheKey, true, now()->addMinutes(5));
    }

    private function clearSpeedAlertCache(Device $device): void
    {
        $cacheKey = "speed_alert_sent_{$device->id}";
        Cache::forget($cacheKey);
    }

    private function shouldSendBatteryAlert(Device $device, ?float $batteryLevel): bool
    {
        if ($batteryLevel === null) return false;
        $batteryThreshold = floor($batteryLevel / 5) * 5;
        $cacheKey = "battery_alert_sent_{$device->id}_{$batteryThreshold}";
        return !Cache::has($cacheKey);
    }

    private function markBatteryAlertSent(Device $device, float $batteryLevel): void
    {
        $batteryThreshold = floor($batteryLevel / 5) * 5;
        $cacheKey = "battery_alert_sent_{$device->id}_{$batteryThreshold}";
        Cache::put($cacheKey, true, now()->addHours(3));
    }

    private function shouldSendRemovalAlert(Device $device): bool
    {
        $cacheKey = "removal_alert_sent_{$device->id}";
        return !Cache::has($cacheKey);
    }

    private function markRemovalAlertSent(Device $device): void
    {
        $cacheKey = "removal_alert_sent_{$device->id}";
        Cache::put($cacheKey, true, now()->addMinutes(30));
    }

    private function shouldSendVibrationAlert(Device $device): bool
    {
        $cacheKey = "vibration_alert_sent_{$device->id}";
        return !Cache::has($cacheKey);
    }

    private function markVibrationAlertSent(Device $device): void
    {
        $cacheKey = "vibration_alert_sent_{$device->id}";
        Cache::put($cacheKey, true, now()->addMinutes(10));
    }

    private function checkGeofenceAlert(Device $device, float $latitude, float $longitude, array $locationData): void
    {
        Log::debug('Geocerca check - Pendiente de implementar', [
            'device_id' => $device->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}