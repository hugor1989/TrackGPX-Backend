<?php

namespace App\Http\Controllers;

use App\Events\DeviceRemovalAlert;
use App\Events\LowBatteryAlert;
use App\Events\SpeedAlertTriggered;
use App\Events\GeofenceAlertTriggered;
use App\Models\Device;
use App\Models\Location;
use App\Models\Geofence;
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

            // 2. ACTUALIZAR EL DISPOSITIVO (Cache para el Mapa)
            // 🔥 EL FILTRO: Solo entramos aquí si NO es 0,0
            if ($validated['latitude'] != 0 && $validated['longitude'] != 0) {
                
                $device->update([
                    'last_connection' => now(),
                    
                    // Datos de ubicación
                    'last_latitude'   => $validated['latitude'],
                    'last_longitude'  => $validated['longitude'],
                    'last_speed'      => $validated['speed'] ?? 0,
                    'last_heading'    => $validated['heading'] ?? 0, // Importante para rotación
                ]);
                
            } else {
                // Si es 0,0 solo actualizamos la hora de conexión para saber que está Online
                $device->update([
                    'last_connection' => now(),
                ]);
            }

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

   /*  private function checkGeofenceAlert(Device $device, float $latitude, float $longitude, array $locationData): void
    {
        Log::debug('Geocerca check - Pendiente de implementar', [
            'device_id' => $device->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    } */

    /**
     * Verificar si el dispositivo entró o salió de alguna geocerca
     */
    private function checkGeofenceAlert(Device $device, float $latitude, float $longitude, array $locationData): void
    {
        try {
            Log::info('🗺️ Verificando geocercas', [
                'device_id' => $device->id,
                'lat' => $latitude,
                'lon' => $longitude,
            ]);

            // Obtener geocercas activas del dispositivo
            $geofences = Geofence::where('device_id', $device->id)
                ->where('is_active', true)
                ->get();

            if ($geofences->isEmpty()) {
                Log::info('📍 No hay geocercas activas para este dispositivo');
                return;
            }

            Log::info('📍 Geocercas encontradas', ['count' => $geofences->count()]);

            foreach ($geofences as $geofence) {
                // Verificar si el horario está habilitado y si estamos en horario válido
                if ($geofence->schedule_enabled && !$this->isInSchedule($geofence)) {
                    Log::info('⏰ Geocerca fuera de horario', ['geofence_id' => $geofence->id]);
                    continue;
                }

                // Verificar si está dentro de la geocerca
                $isInside = $this->isPointInGeofence($latitude, $longitude, $geofence);

                // Obtener el estado anterior desde caché
                $cacheKey = "geofence_state_{$device->id}_{$geofence->id}";
                $wasInside = Cache::get($cacheKey, null);

                Log::info('🔍 Estado de geocerca', [
                    'geofence_id' => $geofence->id,
                    'geofence_name' => $geofence->name,
                    'is_inside' => $isInside,
                    'was_inside' => $wasInside,
                ]);

                // Detectar evento de ENTRADA
                if ($isInside && $wasInside === false && $geofence->alert_on_enter) {
                    if ($this->shouldSendGeofenceAlert($device, $geofence, 'enter')) {
                        Log::info('🟢 DISPARANDO alerta de ENTRADA a geocerca', [
                            'device_id' => $device->id,
                            'geofence_id' => $geofence->id,
                            'geofence_name' => $geofence->name,
                        ]);

                        GeofenceAlertTriggered::dispatch(
                            $device,
                            $geofence,
                            'enter',
                            $locationData
                        );

                        $this->markGeofenceAlertSent($device, $geofence, 'enter');
                    }
                }

                // Detectar evento de SALIDA
                if (!$isInside && $wasInside === true && $geofence->alert_on_exit) {
                    if ($this->shouldSendGeofenceAlert($device, $geofence, 'exit')) {
                        Log::info('🔴 DISPARANDO alerta de SALIDA de geocerca', [
                            'device_id' => $device->id,
                            'geofence_id' => $geofence->id,
                            'geofence_name' => $geofence->name,
                        ]);

                        GeofenceAlertTriggered::dispatch(
                            $device,
                            $geofence,
                            'exit',
                            $locationData
                        );

                        $this->markGeofenceAlertSent($device, $geofence, 'exit');
                    }
                }

                // Actualizar estado en caché (60 minutos)
                Cache::put($cacheKey, $isInside, now()->addMinutes(60));
            }
        } catch (\Exception $e) {
            Log::error('❌ Error verificando geocercas', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verificar si un punto está dentro de una geocerca
     */
    private function isPointInGeofence($lat, $lon, $geofence)
    {
        if ($geofence->type === 'circle') {
            return $this->isPointInCircle(
                $lat,
                $lon,
                $geofence->center_lat,
                $geofence->center_lon,
                $geofence->radius
            );
        } elseif ($geofence->type === 'polygon') {
            return $this->isPointInPolygon($lat, $lon, $geofence->polygon_points);
        }

        return false;
    }

    /**
     * Verificar si un punto está dentro de un círculo (Fórmula Haversine)
     */
    private function isPointInCircle($lat, $lon, $centerLat, $centerLon, $radiusMeters)
    {
        $earthRadius = 6371000; // Radio de la Tierra en metros

        $dLat = deg2rad($centerLat - $lat);
        $dLon = deg2rad($centerLon - $lon);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat)) * cos(deg2rad($centerLat)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return $distance <= $radiusMeters;
    }

    /**
     * Verificar si un punto está dentro de un polígono (Ray Casting Algorithm)
     */
    private function isPointInPolygon($lat, $lon, $polygonPoints)
    {
        if (empty($polygonPoints) || count($polygonPoints) < 3) {
            return false;
        }

        $inside = false;
        $count = count($polygonPoints);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $polygonPoints[$i]['lat'];
            $yi = $polygonPoints[$i]['lon'];
            $xj = $polygonPoints[$j]['lat'];
            $yj = $polygonPoints[$j]['lon'];

            $intersect = (($yi > $lon) != ($yj > $lon)) &&
                ($lat < ($xj - $xi) * ($lon - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Verificar si estamos dentro del horario programado de la geocerca
     */
    private function isInSchedule($geofence)
    {
        if (!$geofence->schedule_enabled) {
            return true;
        }

        $now = Carbon::now('America/Mexico_City');
        $currentDay = strtolower($now->format('l')); // monday, tuesday, etc.
        $currentTime = $now->format('H:i');

        // Verificar día
        if (!empty($geofence->schedule_days) && !in_array($currentDay, $geofence->schedule_days)) {
            return false;
        }

        // Verificar hora
        if ($geofence->schedule_start && $geofence->schedule_end) {
            if ($currentTime < $geofence->schedule_start || $currentTime > $geofence->schedule_end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verificar si debemos enviar alerta de geocerca (cooldown de 5 minutos)
     */
    private function shouldSendGeofenceAlert($device, $geofence, $eventType)
    {
        $cacheKey = "geofence_alert_{$device->id}_{$geofence->id}_{$eventType}";
        return !Cache::has($cacheKey);
    }

    /**
     * Marcar que se envió una alerta de geocerca
     */
    private function markGeofenceAlertSent($device, $geofence, $eventType)
    {
        $cacheKey = "geofence_alert_{$device->id}_{$geofence->id}_{$eventType}";
        Cache::put($cacheKey, true, now()->addMinutes(5)); // Cooldown de 5 minutos
    }
}
