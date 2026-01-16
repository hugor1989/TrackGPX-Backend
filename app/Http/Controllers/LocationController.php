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
            // 1. Validar los datos recibidos
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
            ]);

            // 2. Buscar dispositivo CARGANDO LA RELACIÓN 'members'
            // Asegúrate de haber agregado la función members() en tu modelo Device
            $device = Device::where('imei', $validated['imei'])
                ->with(['customer', 'vehicle', 'alarms', 'members'])
                ->first();

            if (!$device) {
                return response()->json(['success' => false, 'message' => 'Dispositivo no encontrado'], 404);
            }

            // Validaciones de estado
            if ($device->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Dispositivo inactivo'], 403);
            }

            // Procesar Timestamp
            $timestamp = $validated['timestamp']
                ? Carbon::parse($validated['timestamp'])->setTimezone('America/Mexico_City')
                : Carbon::now('America/Mexico_City');

            // 3. Crear la nueva ubicación en la BD
            $location = Location::create([
                'device_id' => $device->id,
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'speed' => $validated['speed'] ?? null,
                'battery_level' => $validated['battery_level'] ?? null,
                'altitude' => $validated['altitude'] ?? null,
                'timestamp' => $timestamp,
            ]);

            // 4. Actualizar estado del Dispositivo (Cache visual)
            if ($validated['latitude'] != 0 && $validated['longitude'] != 0) {
                $device->update([
                    'last_connection' => now(),
                    'last_latitude'   => $validated['latitude'],
                    'last_longitude'  => $validated['longitude'],
                    'last_speed'      => $validated['speed'] ?? 0,
                    'last_heading'    => $validated['heading'] ?? 0,
                ]);
            } else {
                $device->update(['last_connection' => now()]);
            }

            Log::info('✅ Ubicación guardada', ['location_id' => $location->id]);

            $locationData = [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'speed' => $validated['speed'] ?? 0,
                'battery_level' => $validated['battery_level'] ?? null,
                'timestamp' => $timestamp->toIso8601String(),
            ];

            // ============================================
            // 5. LÓGICA DE ALERTAS MULTI-USUARIO (ADMIN + MEMBERS)
            // ============================================

            $alarms = $device->alarms;

            // --- A. Recolectar Destinatarios ---
            $recipients = collect();

            // 1. Agregar Admin (Dueño) si tiene token
            if ($device->customer && !empty($device->customer->expo_push_token)) {
                $recipients->push($device->customer);
            }

            // 2. Agregar Members (Invitados) si tienen token
            if ($device->members) {
                foreach ($device->members as $member) {
                    if (!empty($member->expo_push_token)) {
                        $recipients->push($member);
                    }
                }
            }

            // 3. Eliminar duplicados (por ID)
            $recipients = $recipients->unique('id');

            Log::info('🔍 Verificando alertas', [
                'device_id' => $device->id,
                'recipients_count' => $recipients->count(),
                'recipient_ids' => $recipients->pluck('id')->toArray()
            ]);

            // --- B. Evaluar Alertas solo si hay configuración y destinatarios ---
            if ($alarms && $recipients->count() > 0) {

                // ALERTA DE VELOCIDAD
                if ($alarms->alarm_speed && $alarms->speed_limit && isset($validated['speed'])) {
                    if ($validated['speed'] > $alarms->speed_limit) {
                        if ($this->shouldSendSpeedAlert($device)) {

                            Log::info('🚨 Alerta de Velocidad -> Enviando a ' . $recipients->count() . ' usuarios');

                            // Pasamos $recipients al evento
                            SpeedAlertTriggered::dispatch(
                                $device,
                                (float) $validated['speed'],
                                (float) $alarms->speed_limit,
                                $locationData,
                                $recipients
                            );

                            $this->markSpeedAlertSent($device);
                        }
                    } else {
                        $this->clearSpeedAlertCache($device);
                    }
                }

                // ALERTA DE BATERÍA BAJA
                if ($alarms->alarm_low_battery && isset($validated['battery_level'])) {
                    if ($validated['battery_level'] <= 20) {
                        if ($this->shouldSendBatteryAlert($device, $validated['battery_level'])) {

                            Log::info('🔋 Alerta Batería -> Enviando a ' . $recipients->count() . ' usuarios');

                            LowBatteryAlert::dispatch(
                                $device,
                                (int) $validated['battery_level'],
                                $locationData,
                                $recipients
                            );

                            $this->markBatteryAlertSent($device, $validated['battery_level']);
                        }
                    }
                }

                // ALERTA DE REMOCIÓN
                if ($alarms->alarm_removal && ($validated['removal_detected'] ?? false)) {
                    if ($this->shouldSendRemovalAlert($device)) {
                        DeviceRemovalAlert::dispatch($device, $locationData, $recipients);
                        $this->markRemovalAlertSent($device);
                    }
                }

                // ALERTA DE VIBRACIÓN
                if ($alarms->alarm_vibration && ($validated['vibration_detected'] ?? false)) {
                    if ($this->shouldSendVibrationAlert($device)) {
                        // Asegúrate de crear este evento si no existe o usar uno genérico
                        //DeviceVibrationAlert::dispatch($device, $locationData, $recipients);
                    }
                }

                // ALERTA DE GEOCERCA (Si la manejas aquí)
                if ($alarms->alarm_geofence) {
                    // Deberás actualizar checkGeofenceAlert para que acepte $recipients
                    $this->checkGeofenceAlert($device, $validated['latitude'], $validated['longitude'], $locationData, $recipients);
                }
            } else {
                // Logs de diagnóstico si no se envían alertas
                if (!$alarms) Log::warning('⚠️ Sin configuración de alarmas');
                elseif ($recipients->isEmpty()) Log::warning('⚠️ Alerta detectada pero SIN destinatarios con token (Admin o Members)');
            }

            return response()->json([
                'success' => true,
                'message' => 'Ubicación procesada correctamente',
                'data' => [
                    'location_id' => $location->id,
                    'recipients_notified' => $recipients->count()
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('❌ Error en createInsertLocations: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error interno', 'error' => $e->getMessage()], 500);
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
