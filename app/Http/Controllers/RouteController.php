<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class RouteController extends Controller
{
    // Obtener rutas disponibles por fechas
    public function getDeviceRoutes(Request $request, $deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);

            // Obtener fechas con datos disponibles (últimos 90 días)
            $dates = Location::where('device_id', $device->id)
                ->where('timestamp', '>=', Carbon::now()->subDays(90))
                ->selectRaw('DATE(timestamp) as date, COUNT(*) as points')
                ->groupBy(DB::raw('DATE(timestamp)'))
                ->orderBy('date', 'DESC')
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->date,
                        'points' => $item->points,
                        'formatted' => Carbon::parse($item->date)->format('d/m/Y'),
                    ];
                });

            return response()->json([
                'success' => true,
                'dates' => $dates,
                'device' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'total_locations' => Location::where('device_id', $device->id)->count(),
                ],
                'message' => 'Rutas disponibles obtenidas'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }


    public function getRouteByDate(Request $request, $deviceId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'min_speed' => 'nullable|numeric|min:0',
            'max_speed' => 'nullable|numeric|min:0',
            'compress' => 'nullable|boolean',
            'compress_factor' => 'nullable|integer|min:1|max:100',
            'min_battery' => 'nullable|integer|min:0|max:100',
            'detect_routes' => 'nullable|boolean',
            'max_interval_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $device = Device::findOrFail($deviceId);

            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $minSpeed = $request->min_speed ?? 0;
            $maxSpeed = $request->max_speed ?? 200;
            $minBattery = $request->min_battery ?? 0;
            $detectRoutes = $request->boolean('detect_routes', false);
            $maxIntervalMinutes = $request->max_interval_minutes ?? 5;

            // ✅ LOG DE DEBUG
            Log::info('🔍 Parámetros recibidos:', [
                'device_id' => $deviceId,
                'start_date' => $startDate->toISOString(),
                'end_date' => $endDate->toISOString(),
                'detect_routes' => $detectRoutes,
                'max_interval_minutes' => $maxIntervalMinutes,
            ]);

            // Consulta base - ✅ ORDENAR POR TIMESTAMP
            $query = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$startDate, $endDate])

                // 1. FILTRO ANTI-GHOST (Esto elimina el viaje de África/Océano)
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0)

                // ❌ ELIMINAMOS EL FILTRO DE MÉXICO TEMPORALMENTE
                // Como tus datos son positivos (103.28), el filtro negativo los borraba.
                // Al quitar esto, tus viajes volverán a aparecer.

                ->orderBy('timestamp', 'ASC');

            // Aplicar filtros
            if ($minSpeed > 0) $query->where('speed', '>=', $minSpeed);
            if ($maxSpeed < 200) $query->where('speed', '<=', $maxSpeed);
            if ($minBattery > 0) $query->where('battery_level', '>=', $minBattery);

            $locations = $query->get();

            Log::info('📍 Ubicaciones encontradas:', [
                'count' => $locations->count(),
                'first' => $locations->first() ? [
                    'timestamp' => $locations->first()->timestamp->toISOString(),
                    'lat' => $locations->first()->latitude,
                    'lon' => $locations->first()->longitude,
                ] : null,
                'last' => $locations->last() ? [
                    'timestamp' => $locations->last()->timestamp->toISOString(),
                    'lat' => $locations->last()->latitude,
                    'lon' => $locations->last()->longitude,
                ] : null,
            ]);

            if ($locations->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'mode' => $detectRoutes ? 'multiple_routes' : 'single_route',
                    'routes' => [],
                    'route' => null,
                    'total_routes' => 0,
                    'message' => 'No se encontraron puntos en el rango de fechas'
                ]);
            }

            // ========== MODO: DETECCIÓN DE MÚLTIPLES RUTAS ==========
            if ($detectRoutes) {
                $routes = $this->detectMultipleRoutes($locations, $maxIntervalMinutes, $device);

                Log::info('🛣️ Rutas detectadas:', [
                    'total_routes' => count($routes),
                    'routes_summary' => array_map(function ($route) {
                        return [
                            'id' => $route['id'],
                            'points' => count($route['points']),
                            'start' => $route['start_time'],
                            'end' => $route['end_time'],
                            'duration' => $route['statistics']['duration_human'] ?? 'N/A',
                        ];
                    }, $routes)
                ]);

                // Aplicar compresión a cada ruta si se solicita
                if ($request->compress) {
                    $compressFactor = $request->compress_factor ?? 10;
                    foreach ($routes as &$route) {
                        $originalPoints = count($route['points']);
                        $route['points'] = $this->compressPoints($route['points'], $compressFactor);
                        $route['statistics']['total_points'] = count($route['points']);
                        $route['statistics']['original_points'] = $originalPoints;
                    }
                }

                return response()->json([
                    'success' => true,
                    'mode' => 'multiple_routes',
                    'routes' => $routes,
                    'total_routes' => count($routes),
                    'date_range' => [
                        'start' => $startDate->toISOString(),
                        'end' => $endDate->toISOString(),
                    ],
                    'detection_settings' => [
                        'max_interval_minutes' => $maxIntervalMinutes,
                        'max_interval_seconds' => $maxIntervalMinutes * 60,
                    ],
                    'message' => count($routes) . ' rutas detectadas correctamente'
                ]);
            }

            // ========== MODO: RUTA ÚNICA ==========
            $route = $this->createSingleRoute($locations, $device);

            // Aplicar compresión si se solicita
            if ($request->compress) {
                $compressFactor = $request->compress_factor ?? 10;
                $originalPoints = count($route['points']);
                $route['points'] = $this->compressPoints($route['points'], $compressFactor);
                $route['statistics']['total_points'] = count($route['points']);
                $route['statistics']['original_points'] = $originalPoints;
            }

            return response()->json([
                'success' => true,
                'mode' => 'single_route',
                'route' => [
                    'points' => $route['points'],
                    'statistics' => $route['statistics'],
                    'start_time' => $route['start_time'],
                    'end_time' => $route['end_time'],
                    'device_name' => $route['device_name'],
                    'device_id' => $route['device_id'],
                ],
                'routes' => [],
                'date_range' => [
                    'start' => $startDate->toISOString(),
                    'end' => $endDate->toISOString(),
                ],
                'message' => 'Ruta única generada correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error en getRouteByDate:', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ruta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una ruta única (modo original)
     */
    private function createSingleRoute($locations, $device)
    {
        $points = [];

        foreach ($locations as $location) {

            // Validar timestamp
            if (!$location->timestamp || !$location->timestamp instanceof Carbon) {
                Log::warning('Timestamp inválido en ubicación', ['location_id' => $location->id]);
                continue;
            }
            $longitude = (float) $location->longitude;

            // Si la longitud es positiva pero debería ser negativa (México está en -90 a -120)
            if ($longitude > 0 && $longitude < 180) {
                $longitude = -$longitude;
            }

            $points[] = [
                'lat' => (float) $location->latitude,
                'lon' => $longitude,
                'speed' => $location->speed !== null ? (float) $location->speed : null,
                'altitude' => $location->altitude !== null ? (float) $location->altitude : null,
                'battery' => $location->battery_level !== null ? (int) $location->battery_level : null,
                'timestamp' => $location->timestamp->toISOString(),
            ];
        }

        $route = [
            'points' => $points,
            'start_time' => $locations->first()->timestamp->toISOString(),
            'end_time' => $locations->last()->timestamp->toISOString(),
            'device_name' => $device->name,
            'device_id' => $device->id,
        ];

        return $this->calculateRouteStatistics($route, $device);
    }

    /**
     * 🔥 Detectar múltiples rutas basadas en intervalos de tiempo - CORREGIDO
     */
    private function detectMultipleRoutes($locations, $maxIntervalMinutes = 5, $device = null)
    {
        $routes = [];
        $currentRoute = null;
        $previousTimestamp = null;
        $maxIntervalSeconds = $maxIntervalMinutes * 60;
        $routeIndex = 0;

        Log::info('🔍 Iniciando detección de rutas', [
            'total_locations' => $locations->count(),
            'max_interval_minutes' => $maxIntervalMinutes,
            'max_interval_seconds' => $maxIntervalSeconds,
            'first_timestamp' => $locations->first()->timestamp->toISOString(),
            'last_timestamp' => $locations->last()->timestamp->toISOString(),
        ]);

        foreach ($locations as $index => $location) {
            // Validar timestamp
            if (!$location->timestamp || !($location->timestamp instanceof Carbon)) {
                Log::warning('⚠️ Timestamp inválido omitido', [
                    'location_id' => $location->id,
                    'timestamp' => $location->timestamp
                ]);
                continue;
            }

            // Corregir longitud
            $longitude = (float) $location->longitude;
            if ($longitude > 0 && $longitude < 180) {
                $longitude = -$longitude;
            }

            $currentPoint = [
                'lat' => (float) $location->latitude,
                'lon' => $longitude,
                'speed' => $location->speed !== null ? (float) $location->speed : null,
                'altitude' => $location->altitude !== null ? (float) $location->altitude : null,
                'battery' => $location->battery_level !== null ? (int) $location->battery_level : null,
                'timestamp' => $location->timestamp->toISOString(),
            ];

            $currentTimestamp = $location->timestamp;

            // Si es el primer punto, iniciar nueva ruta
            if ($previousTimestamp === null) {
                $routeIndex++;
                $currentRoute = [
                    'id' => $routeIndex,
                    'points' => [$currentPoint],
                    'start_time' => $currentTimestamp->toISOString(),
                    'end_time' => $currentTimestamp->toISOString(),
                ];

                Log::info("🆕 Ruta #{$routeIndex} iniciada (primer punto)", [
                    'timestamp' => $currentTimestamp->toISOString(),
                    'index' => $index
                ]);
            } else {
                // 🔥 SOLUCIÓN: Usar abs() para obtener valor absoluto
                $timeDiff = abs($currentTimestamp->diffInSeconds($previousTimestamp));

                // 🔥 DEBUG: Mostrar cada 100 puntos
                if ($index % 100 === 0) {
                    Log::info("⏱️ Analizando punto #{$index}", [
                        'current_time' => $currentTimestamp->toISOString(),
                        'previous_time' => $previousTimestamp->toISOString(),
                        'time_diff_seconds' => $timeDiff,
                        'max_allowed_seconds' => $maxIntervalSeconds,
                        'current_route_id' => $currentRoute['id'],
                        'current_route_points' => count($currentRoute['points']),
                    ]);
                }

                // 🔥 Si el intervalo excede el límite, finalizar ruta actual
                if ($timeDiff > $maxIntervalSeconds) {
                    // Finalizar ruta anterior
                    if ($currentRoute && count($currentRoute['points']) > 0) {
                        $currentRoute = $this->calculateRouteStatistics($currentRoute, $device);
                        $routes[] = $currentRoute;

                        Log::info("✅ Ruta #{$currentRoute['id']} finalizada", [
                            'points' => count($currentRoute['points']),
                            'start' => $currentRoute['start_time'],
                            'end' => $currentRoute['end_time'],
                            'duration' => $currentRoute['statistics']['duration_human'],
                            'distance_km' => $currentRoute['statistics']['distance'],
                            'gap_seconds' => $timeDiff,
                            'reason' => "Intervalo de {$timeDiff}s excede límite de {$maxIntervalSeconds}s"
                        ]);
                    }

                    // Iniciar NUEVA ruta con el punto actual
                    $routeIndex++;
                    $currentRoute = [
                        'id' => $routeIndex,
                        'points' => [$currentPoint],
                        'start_time' => $currentTimestamp->toISOString(),
                        'end_time' => $currentTimestamp->toISOString(),
                    ];

                    Log::info("🆕 Ruta #{$routeIndex} iniciada (por intervalo)", [
                        'timestamp' => $currentTimestamp->toISOString(),
                        'gap_from_previous' => $timeDiff . 's (' . round($timeDiff / 60, 1) . ' minutos)',
                        'previous_route_end' => $previousTimestamp->toISOString(),
                    ]);
                } else {
                    // Agregar punto a la ruta actual
                    $currentRoute['points'][] = $currentPoint;
                    $currentRoute['end_time'] = $currentTimestamp->toISOString();
                }
            }

            $previousTimestamp = $currentTimestamp;
        }

        // Agregar la última ruta si existe
        if ($currentRoute && count($currentRoute['points']) > 0) {
            $currentRoute = $this->calculateRouteStatistics($currentRoute, $device);
            $routes[] = $currentRoute;

            Log::info("✅ Última ruta #{$currentRoute['id']} finalizada", [
                'points' => count($currentRoute['points']),
                'start' => $currentRoute['start_time'],
                'end' => $currentRoute['end_time'],
                'duration' => $currentRoute['statistics']['duration_human'],
                'distance_km' => $currentRoute['statistics']['distance'],
            ]);
        }

        Log::info('🏁 Detección completada', [
            'total_routes' => count($routes),
            'total_points_processed' => $locations->count(),
            'routes_summary' => array_map(function ($r) {
                return [
                    'id' => $r['id'],
                    'points' => $r['statistics']['total_points'],
                    'distance_km' => $r['statistics']['distance'],
                    'duration' => $r['statistics']['duration_human'],
                ];
            }, $routes)
        ]);

        return $routes;
    }

    /**
     * Calcular estadísticas para una ruta - VERSIÓN CORREGIDA
     */
    private function calculateRouteStatistics($route, $device = null)
    {
        $points = $route['points'];

        if (count($points) === 0) {
            $route['statistics'] = [
                'total_points' => 0,
                'distance' => 0,
                'duration' => 0,
                'duration_human' => '0s',
                'avg_speed' => 0,
                'max_speed' => 0,
                'min_speed' => 0,
                'avg_battery' => null,
                'min_battery' => null,
                'max_battery' => null,
                'avg_altitude' => null,
                'first_point_time' => null,
                'last_point_time' => null,
            ];
            return $route;
        }

        // ✅ 1. ORDENAR PUNTOS POR TIMESTAMP (por si acaso)
        usort($points, function ($a, $b) {
            return strtotime($a['timestamp']) <=> strtotime($b['timestamp']);
        });

        // ✅ 2. OBTENER PRIMER Y ÚLTIMO TIMESTAMP VÁLIDO
        $firstTimestamp = null;
        $lastTimestamp = null;

        foreach ($points as $point) {
            $ts = strtotime($point['timestamp']);
            if ($ts !== false) {
                if ($firstTimestamp === null || $ts < $firstTimestamp) {
                    $firstTimestamp = $ts;
                }
                if ($lastTimestamp === null || $ts > $lastTimestamp) {
                    $lastTimestamp = $ts;
                }
            }
        }

        // ✅ 3. CALCULAR DURACIÓN CORRECTAMENTE
        $totalDuration = 0;
        if ($firstTimestamp !== null && $lastTimestamp !== null && $lastTimestamp > $firstTimestamp) {
            $totalDuration = $lastTimestamp - $firstTimestamp; // en segundos
        }

        // ✅ 4. CALCULAR DISTANCIA
        $totalDistance = 0;
        $speeds = [];
        $batteries = [];
        $altitudes = [];

        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1];
            $current = $points[$i];

            // Calcular distancia
            $distance = $this->calculateHaversineDistance(
                $prev['lat'],
                $prev['lon'],
                $current['lat'],
                $current['lon']
            );
            $totalDistance += $distance;

            // Recolectar datos para promedios
            if ($current['speed'] !== null) $speeds[] = $current['speed'];
            if ($current['battery'] !== null) $batteries[] = $current['battery'];
            if ($current['altitude'] !== null) $altitudes[] = $current['altitude'];
        }

        // Convertir distancia a km
        $totalDistanceKm = $totalDistance / 1000;

        // ✅ 5. CALCULAR VELOCIDAD PROMEDIO (MEJOR CÁLCULO)
        $avgSpeed = 0;
        if ($totalDuration > 0 && $totalDistanceKm > 0) {
            // Velocidad promedio real = distancia total / tiempo total
            $avgSpeed = ($totalDistanceKm / $totalDuration) * 3600; // km/h
        } elseif (count($speeds) > 0) {
            // Fallback: promedio de velocidades registradas
            $avgSpeed = array_sum($speeds) / count($speeds);
        }

        // ✅ 6. CALCULAR OTROS PROMEDIOS
        $avgBattery = count($batteries) > 0 ? array_sum($batteries) / count($batteries) : null;
        $avgAltitude = count($altitudes) > 0 ? array_sum($altitudes) / count($altitudes) : null;

        $route['statistics'] = [
            'total_points' => count($points),
            'distance' => round($totalDistanceKm, 2),
            'duration' => $totalDuration,
            'duration_human' => $this->secondsToHuman($totalDuration),
            'avg_speed' => round($avgSpeed, 2),
            'max_speed' => count($speeds) > 0 ? round(max($speeds), 2) : 0,
            'min_speed' => count($speeds) > 0 ? round(min($speeds), 2) : 0,
            'avg_battery' => $avgBattery !== null ? round($avgBattery, 1) : null,
            'min_battery' => count($batteries) > 0 ? min($batteries) : null,
            'max_battery' => count($batteries) > 0 ? max($batteries) : null,
            'avg_altitude' => $avgAltitude !== null ? round($avgAltitude, 1) : null,
            'first_point_time' => $firstTimestamp !== null ? date('c', $firstTimestamp) : null,
            'last_point_time' => $lastTimestamp !== null ? date('c', $lastTimestamp) : null,
        ];

        // ✅ 7. ACTUALIZAR START/END TIME DE LA RUTA
        if ($firstTimestamp !== null) {
            $route['start_time'] = date('c', $firstTimestamp);
        }
        if ($lastTimestamp !== null) {
            $route['end_time'] = date('c', $lastTimestamp);
        }

        $route['device_name'] = $device ? $device->name : 'Dispositivo';
        $route['device_id'] = $device ? $device->id : 0;

        // ✅ 8. LOG PARA DEBUG
        Log::info('📊 Estadísticas calculadas:', [
            'points' => count($points),
            'duration_seconds' => $totalDuration,
            'duration_human' => $this->secondsToHuman($totalDuration),
            'distance_km' => $totalDistanceKm,
            'avg_speed_kmh' => $avgSpeed,
            'first_timestamp' => $route['start_time'],
            'last_timestamp' => $route['end_time'],
        ]);

        return $route;
    }

    /**
     * Comprimir puntos de una ruta
     */
    private function compressPoints($points, $compressFactor)
    {
        if ($compressFactor <= 1) return $points;

        $compressed = [];
        foreach ($points as $index => $point) {
            if ($index % $compressFactor === 0) {
                $compressed[] = $point;
            }
        }

        // Siempre incluir el último punto
        if (end($compressed) !== end($points)) {
            $compressed[] = end($points);
        }

        return $compressed;
    }

    /**
     * Función para calcular distancia Haversine (más precisa)
     */
    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Radio de la Tierra en metros

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }

    /**
     * Convertir segundos a formato humano
     */
    private function secondsToHuman($seconds)
    {
        // Manejar valores negativos
        if ($seconds < 0) {
            return '⚠️ ' . $this->secondsToHuman(abs($seconds)) . ' (negativo)';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $result = [];
        if ($hours > 0) $result[] = $hours . 'h';
        if ($minutes > 0) $result[] = $minutes . 'm';
        if ($secs > 0 || empty($result)) $result[] = $secs . 's';

        return implode(' ', $result);
    }


    // Obtener resumen de rutas por día
    public function getRoutesSummary(Request $request, $deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);

            $days = $request->days ?? 7;

            $summary = Location::where('device_id', $device->id)
                ->where('timestamp', '>=', Carbon::now()->subDays($days))
                ->selectRaw('
                    DATE(timestamp) as date,
                    COUNT(*) as total_points,
                    MIN(timestamp) as first_point,
                    MAX(timestamp) as last_point,
                    AVG(speed) as avg_speed,
                    MAX(speed) as max_speed,
                    AVG(battery_level) as avg_battery,
                    AVG(altitude) as avg_altitude
                ')
                ->groupBy(DB::raw('DATE(timestamp)'))
                ->orderBy('date', 'DESC')
                ->get()
                ->map(function ($item) {
                    $first = Carbon::parse($item->first_point);
                    $last = Carbon::parse($item->last_point);
                    $duration = $first->diffInSeconds($last);

                    return [
                        'date' => $item->date,
                        'formatted_date' => Carbon::parse($item->date)->format('d/m/Y'),
                        'total_points' => (int) $item->total_points,
                        'time_range' => [
                            'start' => $first->format('H:i'),
                            'end' => $last->format('H:i'),
                            'duration_seconds' => $duration,
                            'duration_human' => $this->secondsToHuman($duration),
                        ],
                        'statistics' => [
                            'avg_speed' => $item->avg_speed !== null ? round($item->avg_speed, 2) : null,
                            'max_speed' => $item->max_speed !== null ? round($item->max_speed, 2) : null,
                            'avg_battery' => $item->avg_battery !== null ? round($item->avg_battery, 1) : null,
                            'avg_altitude' => $item->avg_altitude !== null ? round($item->avg_altitude, 1) : null,
                        ],
                        'has_data' => $item->total_points > 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'device' => [
                    'id' => $device->id,
                    'name' => $device->name,
                ],
                'message' => 'Resumen de rutas obtenido'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    // Exportar ruta a GPX/KML
    public function exportRoute(Request $request, $deviceId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:gpx,kml,json',
            'include_metadata' => 'boolean',
            'simplify' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $device = Device::findOrFail($deviceId);

            $routeRequest = new Request([
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'compress' => $request->simplify ?? true,
                'compress_factor' => $request->simplify ? 5 : 1,
            ]);

            $routeResponse = $this->getRouteByDate($routeRequest, $deviceId);
            $routeData = json_decode($routeResponse->getContent(), true);

            if (!$routeData['success']) {
                return response()->json($routeData, 400);
            }

            $route = $routeData['route'];

            if (empty($route['points'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay puntos para exportar en el rango de fechas seleccionado'
                ], 400);
            }

            $filename = "ruta_" . str_slug($device->name) . "_" .
                Carbon::parse($request->start_date)->format('Y-m-d') . "_" .
                Carbon::parse($request->end_date)->format('Y-m-d');

            if ($request->format === 'gpx') {
                $content = $this->generateGPX($device, $route, $request->include_metadata ?? true);
                $filename .= '.gpx';
                $contentType = 'application/gpx+xml';
            } elseif ($request->format === 'kml') {
                $content = $this->generateKML($device, $route, $request->include_metadata ?? true);
                $filename .= '.kml';
                $contentType = 'application/vnd.google-earth.kml+xml';
            } else {
                $content = json_encode([
                    'metadata' => [
                        'device' => $device->name,
                        'export_date' => now()->toISOString(),
                        'date_range' => [
                            'start' => $request->start_date,
                            'end' => $request->end_date,
                        ],
                    ],
                    'route' => $route,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                $filename .= '.json';
                $contentType = 'application/json';
            }

            return response($content, 200, [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => strlen($content),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar ruta: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateGPX($device, $route, $includeMetadata = true)
    {
        $gpx = '<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="GPS Tracker App"
     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
     xmlns="http://www.topografix.com/GPX/1/1"
     xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">';

        if ($includeMetadata) {
            $gpx .= '
  <metadata>
    <name>Ruta - ' . htmlspecialchars($device->name) . '</name>
    <desc>Ruta del ' . $route['start_time'] . ' al ' . $route['end_time'] . '</desc>
    <time>' . now()->toISOString() . '</time>
  </metadata>';
        }

        $gpx .= '
  <trk>
    <name>' . htmlspecialchars($device->name) . '</name>
    <trkseg>';

        foreach ($route['points'] as $point) {
            $gpx .= '
      <trkpt lat="' . $point['lat'] . '" lon="' . $point['lon'] . '">';

            if ($point['altitude'] !== null) {
                $gpx .= '
        <ele>' . $point['altitude'] . '</ele>';
            }

            $gpx .= '
        <time>' . $point['timestamp'] . '</time>
      </trkpt>';
        }

        $gpx .= '
    </trkseg>
  </trk>
</gpx>';

        return $gpx;
    }

    private function generateKML($device, $route, $includeMetadata = true)
    {
        $kml = '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <name>Ruta - ' . htmlspecialchars($device->name) . '</name>
    <Placemark>
      <LineString>
        <coordinates>';

        foreach ($route['points'] as $point) {
            $kml .= $point['lon'] . ',' . $point['lat'] . ',' . ($point['altitude'] ?? 0) . ' ';
        }

        $kml .= '</coordinates>
      </LineString>
    </Placemark>
  </Document>
</kml>';

        return $kml;
    }


    public function getActivityByDay(Request $request, $deviceId)
    {
        // 1. VALIDACIÓN
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'max_interval_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
        }

        $device = Device::findOrFail($deviceId);

        // 2. OBTENER DATOS (Incluyendo filtro básico de SQL)
        $locations = Location::where('device_id', $device->id)
            ->whereBetween('timestamp', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay(),
            ])
            ->where('latitude', '!=', 0) // Filtro SQL básico
            ->where('longitude', '!=', 0)
            ->orderBy('timestamp', 'ASC')
            ->get();

        if ($locations->isEmpty()) {
            return response()->json(['success' => true, 'summary' => null, 'days' => []]);
        }

        // 3. SANITIZACIÓN PROFUNDA + CORRECCIÓN DE SIGNO
        $cleanLocations = $locations->map(function ($loc) {
            $lat = (float)$loc->latitude;
            $lon = (float)$loc->longitude;

            // Corrección China -> México (Si es positivo, lo hacemos negativo)
            if ($lon > 80 && $lon < 180) $lon = $lon * -1;

            // Actualizamos el objeto en memoria
            $loc->latitude = $lat;
            $loc->longitude = $lon;
            return $loc;
        });

        // 4. DETECCIÓN DE RUTAS (Usamos tu lógica para tiempos y cortes)
        $routes = $this->detectMultipleRoutes(
            $cleanLocations,
            $request->max_interval_minutes ?? 5,
            $device
        );

        // 5. AGRUPAR Y RE-CALCULAR DISTANCIA MANUALMENTE
        $days = collect($routes)->groupBy(function ($route) {
            return Carbon::parse($route['start_time'])->toDateString();
        })->map(function ($dayRoutes, $date) {

            $activeMinutes = collect($dayRoutes)->sum(function ($r) {
                return Carbon::parse($r['start_time'])->diffInMinutes(Carbon::parse($r['end_time']));
            });

            // 🔥 AQUI ESTA LA MAGIA: Re-calculamos la distancia punto por punto
            // Ignorando lo que diga $r['statistics']['distance']
            $realDayDistance = 0;

            foreach ($dayRoutes as $route) {
                $points = $route['points'] ?? []; // Asegúrate que tu detectMultipleRoutes devuelva 'points'
                $routeDist = 0;

                for ($i = 0; $i < count($points) - 1; $i++) {
                    $p1 = $points[$i];
                    $p2 = $points[$i + 1];

                    // Calculamos distancia real entre puntos
                    $dist = $this->haversineGreatCircleDistance(
                        $p1['latitude'] ?? $p1['lat'],
                        $p1['longitude'] ?? $p1['lon'],
                        $p2['latitude'] ?? $p2['lat'],
                        $p2['longitude'] ?? $p2['lon']
                    );

                    // FILTRO ANTI-TELETRANSPORTACIÓN
                    // Si la distancia entre dos reportes (segundos de diferencia) es > 500km, es un error.
                    // Un coche no viaja 500km en 1 minuto.
                    if ($dist < 200) {
                        $routeDist += $dist;
                    }
                }
                $realDayDistance += $routeDist;
            }

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->locale('es')->translatedFormat('l d F'),
                'routes_count' => count($dayRoutes),
                'distance_km' => round($realDayDistance / 1000, 2), // Convertir metros a KM
                'start_time' => min(array_column($dayRoutes->toArray(), 'start_time')),
                'end_time' => max(array_column($dayRoutes->toArray(), 'end_time')),
                'active_minutes' => $activeMinutes,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'summary' => [
                'total_distance_km' => round($days->sum('distance_km'), 2),
                'total_routes' => $days->sum('routes_count'),
                'active_days' => $days->count(),
                'total_active_minutes' => $days->sum('active_minutes'),
            ],
            'days' => $days,
        ]);
    }

    // AGREGAR ESTA FUNCIÓN AUXILIAR EN TU CONTROLADOR
    private function haversineGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
    {
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius; // Devuelve metros
    }

    /**
     * Exportar Reporte de Actividad a CSV (Excel)
     */
    public function exportActivityReport(Request $request, $deviceId)
    {
        // 1. Configuración
        $device = Device::findOrFail($deviceId);
        $timezone = 'America/Mexico_City'; // Ajusta a tu zona
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->endOfDay();

        // 2. OBTENER DATOS (Ordenados cronológicamente es VITAL)
        $locations = Location::where('device_id', $device->id)
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->where('latitude', '!=', 0)
            ->orderBy('timestamp', 'ASC') // 🔥 ESTO EVITA TIEMPOS NEGATIVOS
            ->get();

        // 3. PROCESAMIENTO INTELIGENTE POR DÍA
        $daysData = [];
        $periodSummary = [
            'total_km' => 0,
            'max_speed' => 0,
            'move_time' => 0
        ];

        // Iteramos día por día del rango
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $dateKey = $current->format('Y-m-d');

            // Filtramos puntos de ESTE día
            $dayPoints = $locations->filter(function ($loc) use ($dateKey, $timezone) {
                return Carbon::parse($loc->timestamp)->setTimezone($timezone)->format('Y-m-d') === $dateKey;
            })->values();

            if ($dayPoints->count() > 1) {
                $dayDist = 0;
                $dayMaxSpeed = 0;
                $moveTimeMinutes = 0;

                // Puntos de inicio y fin REALES de movimiento
                $firstMove = null;
                $lastMove = null;

                for ($i = 0; $i < $dayPoints->count() - 1; $i++) {
                    $p1 = $dayPoints[$i];
                    $p2 = $dayPoints[$i + 1];

                    // Distancia
                    $dist = $this->haversine($p1->latitude, $p1->longitude, $p2->latitude, $p2->longitude);

                    // Filtros de Calidad:
                    // 1. Ignorar saltos locos (> 200km/h imposibles)
                    // 2. Velocidad máxima realista
                    $speed = $p1->speed;

                    if ($dist < 5000) { // Menos de 5km entre reporte y reporte (filtro anti-salto)
                        $dayDist += $dist;
                        if ($speed > $dayMaxSpeed && $speed < 180) $dayMaxSpeed = $speed; // Filtro max 180km/h
                    }

                    // Lógica de Tiempo: Solo si se movió o encendió
                    if ($speed > 3) {
                        if (!$firstMove) $firstMove = Carbon::parse($p1->timestamp)->setTimezone($timezone);
                        $lastMove = Carbon::parse($p2->timestamp)->setTimezone($timezone);
                    }
                }

                // Guardamos datos del día
                $km = round($dayDist / 1000, 2);
                $daysData[] = [
                    'date_human' => $current->locale('es')->isoFormat('dddd D [de] MMMM'),
                    'is_active' => $km > 0.5, // Consideramos activo si movió más de 500 metros
                    'distance' => $km,
                    'max_speed' => round($dayMaxSpeed),
                    'start_time' => $firstMove ? $firstMove->format('H:i') : '--',
                    'end_time' => $lastMove ? $lastMove->format('H:i') : '--',
                    'duration' => ($firstMove && $lastMove) ? $firstMove->diff($lastMove)->format('%Hh %Im') : '--'
                ];

                // Sumar al total
                $periodSummary['total_km'] += $km;
                if ($dayMaxSpeed > $periodSummary['max_speed']) $periodSummary['max_speed'] = $dayMaxSpeed;
                if ($firstMove && $lastMove) $periodSummary['move_time'] += $firstMove->diffInMinutes($lastMove);
            } else {
                // Día vacío
                $daysData[] = [
                    'date_human' => $current->locale('es')->isoFormat('dddd D [de] MMMM'),
                    'is_active' => false,
                    'distance' => 0,
                    'max_speed' => 0,
                    'start_time' => '--',
                    'end_time' => '--',
                    'duration' => '--'
                ];
            }
            $current->addDay();
        }

        // 4. PREPARAR LOGO (Base64 para que no falle en PDF)
        // Asegúrate de tener logo.png en public/images/
        $path = public_path('images/logo.png');
        $logoData = '';
        if (file_exists($path)) {
            $type = pathinfo($path, PATHINFO_EXTENSION);
            $data = file_get_contents($path);
            $logoData = 'data:image/' . $type . ';base64,' . base64_encode($data);
        }

        // 5. GENERAR PDF
        $pdf = Pdf::loadView('reports.activity_pdf', [
            'device' => $device,
            'client_name' => 'Transportes Logísticos S.A.', // Puedes sacarlo de $device->user->name
            'range' => $request->start_date . ' al ' . $request->end_date,
            'days' => $daysData,
            'summary' => $periodSummary,
            'logo' => $logoData,
            'generated_at' => now()->setTimezone($timezone)->format('d/m/Y H:i')
        ]);

        // OJO: setPaper 'a4' es estándar para imprimir
        return $pdf->setPaper('a4', 'portrait')->download("Reporte_{$device->name}.pdf");
    }

    /**
     * Helper para calcular estadísticas de un día (Usado por JSON y Exportación)
     */
    private function calculateDayStats($locations, $timezone)
    {
        if ($locations->isEmpty()) {
            return [
                'distance_km' => 0,
                'fuel' => 0,
                'routes_count' => 0,
                'duration_human' => '0h 0m',
                'start_time' => '--',
                'end_time' => '--',
                'max_speed' => 0
            ];
        }

        $distance = 0;
        $routesCount = 0;
        $prev = null;
        $lastTime = null;
        $inRoute = false;
        $maxSpeed = 0;

        // Tiempos
        $firstTime = null;
        $lastRouteTime = null;
        $totalSeconds = 0;

        foreach ($locations as $loc) {
            $currTime = \Carbon\Carbon::parse($loc->timestamp)->setTimezone($timezone);

            if ($prev) {
                // Distancia
                $d = $this->calculateHaversineDistance(
                    $prev->latitude,
                    $prev->longitude,
                    $loc->latitude,
                    $loc->longitude
                );

                // Filtro de ruido (Drift)
                if ($d > 0.005 && $d < 2.0) {
                    $distance += $d;
                }

                // Velocidad Máxima
                if ($loc->speed > $maxSpeed) {
                    $maxSpeed = $loc->speed;
                }

                // Detección de Rutas (Corte 5 min)
                $diff = $currTime->diffInSeconds($lastTime);

                if ($diff > 300) {
                    if ($inRoute) {
                        $routesCount++;
                        // Sumar duración de la ruta anterior
                        if ($firstTime && $lastRouteTime) {
                            $totalSeconds += $lastRouteTime->diffInSeconds($firstTime);
                        }
                    }
                    $inRoute = false;
                    $firstTime = null; // Reiniciar inicio de ruta
                } else {
                    if (!$inRoute) {
                        $inRoute = true;
                        $firstTime = $lastTime; // El inicio fue el punto anterior
                    }
                    $lastRouteTime = $currTime;
                }
            }
            $prev = $loc;
            $lastTime = $currTime;
        }

        // Contar la última ruta pendiente
        if ($inRoute) {
            $routesCount++;
            if ($firstTime && $lastRouteTime) {
                $totalSeconds += $lastRouteTime->diffInSeconds($firstTime);
            }
        }

        // Horas de inicio y fin del día (Primer y último punto registrado)
        $dayStart = \Carbon\Carbon::parse($locations->first()->timestamp)->setTimezone($timezone)->format('H:i');
        $dayEnd = \Carbon\Carbon::parse($locations->last()->timestamp)->setTimezone($timezone)->format('H:i');

        return [
            'distance_km' => round($distance, 2),
            'fuel' => round($distance / 10, 1), // Rendimiento estimado
            'routes_count' => $routesCount,
            'duration_human' => $this->secondsToHuman($totalSeconds),
            'start_time' => $dayStart,
            'end_time' => $dayEnd,
            'max_speed' => round($maxSpeed, 0)
        ];
    }

    /**
     * 🔔 Reporte de Historial de Alarmas
     */
    public function getAlarmsReport(Request $request, $deviceId)
    {
        // 1. Validaciones
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'alarm_type' => 'nullable|string',
            'unread_only' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos', 'errors' => $validator->errors()], 400);
        }

        try {
            // 2. Obtener Dispositivo
            $device = Device::with('configuration')->findOrFail($deviceId);

            $deviceName = $device->configuration->custom_name
                ?? $device->model
                ?? 'Dispositivo ' . $device->imei;

            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();

            // 3. Consulta a Base de Datos
            $query = DB::table('notifications')
                ->where('data->device_id', $device->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->select('notifications.*')
                ->orderBy('created_at', 'DESC');

            if ($request->alarm_type) {
                $query->where('type', $request->alarm_type);
            }

            if ($request->boolean('unread_only')) {
                $query->where('is_read', 0);
            }

            $notifications = $query->get();

            // 4. Transformación de Datos (AQUÍ ESTÁ LA MAGIA 🪄)
            $processedAlarms = $notifications->map(function ($notification) {
                // Decodificar JSON de forma segura
                $data = is_string($notification->data) ? json_decode($notification->data, true) : $notification->data;
                if (!is_array($data)) $data = [];

                // Obtener valores crudos
                $lat = (float)($data['latitude'] ?? 0);
                $lon = (float)($data['longitude'] ?? 0);
                $speed = (float)($data['speed'] ?? 0);
                $battery = isset($data['battery_level']) ? (int)$data['battery_level'] : null;

                // 🔥 CORRECCIÓN AUTOMÁTICA DE COORDENADAS (China -> México)
                // Si la longitud es positiva (ej. 103) pero estamos en América, invertirla.
                if ($lon > 80 && $lon < 180) {
                    $lon = $lon * -1;
                }

                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title ?? 'Alerta',
                    'message' => $notification->message,
                    'timestamp' => $notification->created_at,
                    'is_read' => (bool)$notification->is_read,

                    // Enviamos los datos YA CORREGIDOS al nivel raíz
                    // Esto facilita la vida a tu Frontend (React Native)
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'speed' => $speed,
                    'battery' => $battery,

                    // Mantenemos data original por si acaso, pero ya no es vital
                    'data' => $data,
                ];
            });

            // 5. Agrupaciones para las gráficas
            $groupedByType = $processedAlarms->groupBy('type')->map(function ($items, $type) {
                return [
                    'type' => $type,
                    'count' => $items->count(),
                    'unread_count' => $items->where('is_read', false)->count(),
                ];
            })->values();

            // 6. Retornar Respuesta
            return response()->json([
                'success' => true,
                'device' => [
                    'id' => $device->id,
                    'name' => $deviceName,
                    'imei' => $device->imei
                ],
                'date_range' => [
                    'start' => $startDate->toISOString(),
                    'end' => $endDate->toISOString(),
                ],
                'summary' => [
                    'total_alarms' => $notifications->count(),
                    'unread_alarms' => $notifications->where('is_read', 0)->count(),
                    'by_type' => $groupedByType,
                ],
                'alarms' => $processedAlarms, // Enviamos la lista procesada
                'message' => $notifications->count() . ' alarmas encontradas'
            ]);
        } catch (\Exception $e) {
            Log::error('Error reporte alarmas:', ['msg' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 📥 Exportar Reporte de Alarmas a CSV
     */
    public function exportAlarmsReport(Request $request, $deviceId)
    {
        // 1. Validaciones
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'alarm_type' => 'nullable|string',
            'format'     => 'nullable|string|in:csv,pdf' // Nuevo campo
        ]);

        if ($validator->fails()) return response()->json(['success' => false], 400);

        try {
            $device = Device::findOrFail($deviceId);
            $timezone = 'America/Mexico_City';
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $format = $request->format ?? 'csv'; // Por defecto CSV

            // 2. Construir Query Base (Común para ambos)
            $query = DB::table('notifications')
                ->where('data->device_id', $device->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'DESC');

            if ($request->alarm_type && $request->alarm_type !== 'all') {
                $query->where('type', $request->alarm_type);
            }

            // ==========================================
            // OPCIÓN A: GENERAR PDF (Reporte Ejecutivo)
            // ==========================================
            if ($format === 'pdf') {
                // Para PDF traemos todo (limitado a un número razonable, ej. 500)
                // PDF no soporta streaming chunked tan fácil, se renderiza en memoria.
                $notifications = $query->limit(500)->get()->map(function ($notif) use ($timezone) {
                    // Procesar datos para la vista
                    $data = is_string($notif->data) ? json_decode($notif->data, true) : $notif->data;
                    $lat = (float)($data['latitude'] ?? 0);
                    $lon = (float)($data['longitude'] ?? 0);

                    // 🔥 CORRECCIÓN CHINA -> MÉXICO
                    if ($lon > 80 && $lon < 180) $lon = $lon * -1;

                    $notif->formatted_date = Carbon::parse($notif->created_at)->setTimezone($timezone);
                    $notif->lat = $lat;
                    $notif->lon = $lon;
                    $notif->speed = $data['speed'] ?? 0;
                    $notif->maps_link = ($lat && $lon) ? "https://www.google.com/maps?q={$lat},{$lon}" : '#';

                    return $notif;
                });

                $pdfData = [
                    'device' => $device,
                    'alarms' => $notifications,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                    'date_now' => now()->setTimezone($timezone)->format('d/m/Y H:i'),
                    // 'logo' => public_path('img/logo.png') // Descomenta si tienes logo
                ];

                // Cargar vista Blade
                $pdf = Pdf::loadView('reports.alarms_pdf', $pdfData);
                return $pdf->download("Reporte_{$device->name}.pdf");
            }

            // ==========================================
            // OPCIÓN B: GENERAR CSV (Datos Crudos)
            // ==========================================
            $fileName = "Alarmas_{$device->name}_" . date('Y-m-d_His') . ".csv";
            $headers = [
                "Content-type" => "text/csv",
                "Content-Disposition" => "attachment; filename=$fileName",
                "Pragma" => "no-cache",
                "Expires" => "0"
            ];

            $callback = function () use ($query, $device, $request, $timezone) {
                $file = fopen('php://output', 'w');
                fputs($file, "\xEF\xBB\xBF"); // BOM

                // Encabezados Estéticos
                fputcsv($file, ['REPORTE DE ALARMAS']);
                fputcsv($file, ['Dispositivo:', $device->name]);
                fputcsv($file, ['Periodo:', $request->start_date . ' al ' . $request->end_date]);
                fputcsv($file, []);
                fputcsv($file, ['Fecha', 'Hora', 'Tipo', 'Mensaje', 'Velocidad (km/h)', 'Latitud', 'Longitud', 'Google Maps']);

                $query->chunk(500, function ($notifications) use ($file, $timezone) {
                    foreach ($notifications as $notif) {
                        $extraData = is_string($notif->data) ? json_decode($notif->data, true) : $notif->data;
                        $date = Carbon::parse($notif->created_at)->setTimezone($timezone);

                        $lat = (float)($extraData['latitude'] ?? 0);
                        $lon = (float)($extraData['longitude'] ?? 0);

                        // 🔥 CORRECCIÓN CHINA -> MÉXICO EN CSV TAMBIÉN
                        if ($lon > 80 && $lon < 180) $lon = $lon * -1;

                        // Link corregido de Google Maps
                        $mapsLink = ($lat && $lon) ? "https://www.google.com/maps?q={$lat},{$lon}" : '';

                        fputcsv($file, [
                            $date->format('Y-m-d'),
                            $date->format('H:i:s'),
                            $notif->type,
                            $notif->message,
                            $extraData['speed'] ?? 0,
                            $lat,
                            $lon,
                            $mapsLink
                        ]);
                    }
                });
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDailyActivityReport(Request $request, $deviceId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $timezone = 'America/Mexico_City';

        $start = Carbon::parse($request->start_date, $timezone)->startOfDay()->setTimezone('UTC');
        $end   = Carbon::parse($request->end_date, $timezone)->endOfDay()->setTimezone('UTC');

        $device = Device::findOrFail($deviceId);

        $locations = Location::where('device_id', $device->id)
            ->whereBetween('timestamp', [$start, $end])
            ->where('latitude', '!=', 0)
            ->orderBy('timestamp')
            ->get();

        if ($locations->isEmpty()) {
            return response()->json([
                'success' => true,
                'summary' => null,
                'days' => []
            ]);
        }

        // AGRUPAR POR DÍA (hora local)
        $grouped = $locations->groupBy(
            fn($l) =>
            Carbon::parse($l->timestamp)->setTimezone($timezone)->format('Y-m-d')
        );

        $days = [];
        $totalDistance = 0;
        $totalRoutes = 0;
        $totalDuration = 0;

        foreach ($grouped as $date => $points) {

            $routes = [];
            $current = [];
            $last = null;

            foreach ($points as $p) {
                if ($last) {
                    $diff = Carbon::parse($p->timestamp)->diffInSeconds($last->timestamp);
                    if ($diff > 300) {
                        if (count($current) > 1) {
                            $routes[] = $this->buildRoute($current, $timezone);
                        }
                        $current = [];
                    }
                }
                $current[] = $p;
                $last = $p;
            }

            if (count($current) > 1) {
                $routes[] = $this->buildRoute($current, $timezone);
            }

            $dayDistance = collect($routes)->sum('distance_km');
            $dayDuration = collect($routes)->sum('duration_seconds');

            $days[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->locale('es')->isoFormat('dddd D'),
                'distance_km' => round($dayDistance, 2),
                'routes_count' => count($routes),
                'routes' => $routes
            ];

            $totalDistance += $dayDistance;
            $totalRoutes += count($routes);
            $totalDuration += $dayDuration;
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'total_distance_km' => round($totalDistance, 2),
                'total_routes' => $totalRoutes,
                'active_days' => count($days),
                'total_duration_seconds' => $totalDuration
            ],
            'days' => $days
        ]);
    }

    private function buildRoute($points, $timezone)
    {
        $distance = 0;
        $prev = null;

        foreach ($points as $p) {
            if ($prev) {
                $d = $this->haversine(
                    $prev->latitude,
                    $prev->longitude,
                    $p->latitude,
                    $p->longitude
                );
                if ($d > 0.005 && $d < 5) {
                    $distance += $d;
                }
            }
            $prev = $p;
        }

        $start = Carbon::parse($points[0]->timestamp)->setTimezone($timezone);
        $end = Carbon::parse(end($points)->timestamp)->setTimezone($timezone);

        return [
            'start_time' => $start->format('h:i A'),
            'end_time' => $end->format('h:i A'),
            'distance_km' => round($distance, 2),
            'duration_seconds' => $end->diffInSeconds($start),
        ];
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;
        return $r * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    //Reporte de paradas
    public function getStopsReport(Request $request, $deviceId)
    {
        // 1. Configuración
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'min_minutes' => 'nullable|integer|min:1', // Mínimo tiempo para considerar parada (default 3 min)
        ]);

        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 400);

        try {
            $device = Device::findOrFail($deviceId);
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $minDurationMinutes = $request->min_minutes ?? 5; // Default: 5 minutos
            $minSpeedKmh = 5; // Umbral de "cero movimiento"

            // 2. Traer puntos crudos (Aplicamos filtros Anti-China y Anti-Ghost aquí mismo)
            $locations = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$start, $end])
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0)
                ->orderBy('timestamp', 'ASC')
                ->get(['latitude', 'longitude', 'speed', 'timestamp', 'battery_level']);

            $stops = [];
            $currentStop = null;

            // 3. ALGORITMO DE DETECCIÓN DE PARADAS
            foreach ($locations as $loc) {
                // A. Sanitización de coordenadas (China -> México)
                $lat = (float)$loc->latitude;
                $lon = (float)$loc->longitude;
                if ($lon > 80 && $lon < 180) $lon = $lon * -1;
                $speed = (float)$loc->speed;

                $isStopped = $speed < $minSpeedKmh;

                if ($isStopped) {
                    // Si ya estamos en una parada, la actualizamos
                    if ($currentStop) {
                        $currentStop['end_time'] = $loc->timestamp;
                        $currentStop['last_lat'] = $lat; // Guardamos la última posición conocida
                        $currentStop['last_lon'] = $lon;
                    } else {
                        // Iniciamos nueva parada candidata
                        $currentStop = [
                            'start_time' => $loc->timestamp,
                            'end_time' => $loc->timestamp,
                            'lat' => $lat,
                            'lon' => $lon,
                            'battery' => $loc->battery_level
                        ];
                    }
                } else {
                    // El auto se movió. Si teníamos una parada abierta, la cerramos.
                    if ($currentStop) {
                        $this->closeStop($stops, $currentStop, $minDurationMinutes);
                        $currentStop = null;
                    }
                }
            }
            // Cerrar la última si quedó abierta
            if ($currentStop) {
                $this->closeStop($stops, $currentStop, $minDurationMinutes);
            }

            // 4. Resumen
            $totalMinutesStopped = collect($stops)->sum('duration_minutes');

            return response()->json([
                'success' => true,
                'summary' => [
                    'total_stops' => count($stops),
                    'total_duration_minutes' => $totalMinutesStopped,
                    'total_duration_human' => $this->minutesToHuman($totalMinutesStopped)
                ],
                'stops' => array_reverse($stops) // Las más recientes primero
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Helpers privados
    private function closeStop(&$stops, $currentStop, $minDuration)
    {
        $start = Carbon::parse($currentStop['start_time']);
        $end = Carbon::parse($currentStop['end_time']);
        $duration = $start->diffInMinutes($end);

        if ($duration >= $minDuration) {
            $stops[] = [
                'start_time' => $start->format('Y-m-d H:i:s'),
                'end_time' => $end->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration,
                'duration_human' => $this->minutesToHuman($duration),
                'latitude' => $currentStop['lat'],
                'longitude' => $currentStop['lon'],
                'battery' => $currentStop['battery']
            ];
        }
    }


    //Exportar archivo reporte de rutas o viajes
    /*  public function exportTripsReport(Request $request, $deviceId)
    {
        // 1. Validación
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'format'     => 'nullable|in:csv,pdf'
        ]);

        try {
            $device = Device::findOrFail($deviceId);
            $format = $request->format ?? 'csv';
            $timezone = 'America/Mexico_City';

            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            // 2. OBTENER DATOS Y DETECTAR RUTAS
            // Usamos tu lógica existente para obtener los puntos limpios
            $locations = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$start, $end])
                ->where('latitude', '!=', 0)
                ->orderBy('timestamp', 'ASC')
                ->get();

            // Llamamos a tu función interna para cortar los viajes
            // (Asegúrate de que detectMultipleRoutes sea accesible o cópiala si es privada)
            $routesRaw = $this->detectMultipleRoutes($locations, 10, $device);

            // 3. ENRIQUECER DATOS PARA EL REPORTE
            $tripsData = [];
            $summary = ['total_trips' => 0, 'total_km' => 0, 'total_time' => 0, 'max_speed' => 0];

            foreach ($routesRaw as $route) {
                $distKm = $route['statistics']['distance'] ?? 0;
                $durationSec = $route['statistics']['duration'] ?? 0;
                $maxSpeed = $route['statistics']['max_speed'] ?? 0;

                // Puntos de inicio y fin
                $startPt = $route['points'][0];
                $endPt = end($route['points']);

                $trip = [
                    'start_time' => Carbon::parse($route['start_time'])->setTimezone($timezone)->format('H:i'),
                    'end_time'   => Carbon::parse($route['end_time'])->setTimezone($timezone)->format('H:i'),
                    'date'       => Carbon::parse($route['start_time'])->setTimezone($timezone)->format('d/m/Y'),
                    'distance'   => $distKm,
                    'duration'   => $route['statistics']['duration_human'],
                    'max_speed'  => $maxSpeed,
                    'fuel_est'   => round(($distKm / 10) * 24, 2), // Ejemplo: $24 pesos x litro (10km/L)

                    // Coordenadas para links
                    'start_lat'  => $startPt['lat'],
                    'start_lon'  => $startPt['lon'],
                    'end_lat'    => $endPt['lat'],
                    'end_lon'    => $endPt['lon'],
                ];

                $tripsData[] = $trip;

                // Sumar al resumen global
                $summary['total_trips']++;
                $summary['total_km'] += $distKm;
                $summary['total_time'] += $durationSec;
                if ($maxSpeed > $summary['max_speed']) $summary['max_speed'] = $maxSpeed;
            }

            // ==========================================
            // 🅰️ SALIDA PDF
            // ==========================================
            if ($format === 'pdf') {
                $logoPath = public_path('images/logo.png');
                $logoData = null;
                if (file_exists($logoPath)) {
                    $type = pathinfo($logoPath, PATHINFO_EXTENSION);
                    $data = file_get_contents($logoPath);
                    $logoData = 'data:image/' . $type . ';base64,' . base64_encode($data);
                }

                $pdf = Pdf::loadView('reports.trips_pdf', [
                    'device' => $device,
                    'trips' => $tripsData,
                    'summary' => $summary,
                    'range' => $request->start_date . ' al ' . $request->end_date,
                    'logo' => $logoData,
                    'generated_at' => now()->setTimezone($timezone)->format('d/m/Y H:i')
                ]);

                return $pdf->setPaper('a4', 'landscape')->download("Viajes_{$device->name}.pdf");
            }

            // ==========================================
            // 🅱️ SALIDA CSV
            // ==========================================
            $fileName = "Viajes_{$device->name}.csv";
            $headers = ["Content-type" => "text/csv", "Content-Disposition" => "attachment; filename=$fileName", "Pragma" => "no-cache"];

            $callback = function () use ($tripsData, $device, $request) {
                $file = fopen('php://output', 'w');
                fputs($file, "\xEF\xBB\xBF");
                fputcsv($file, ['REPORTE DE VIAJES DETALLADO']);
                fputcsv($file, ['Unidad:', $device->name]);
                fputcsv($file, ['Periodo:', $request->start_date . ' - ' . $request->end_date]);
                fputcsv($file, []);
                fputcsv($file, ['Fecha', 'Hora Inicio', 'Hora Fin', 'Distancia (km)', 'Duración', 'Costo Est. ($)', 'Link Inicio', 'Link Fin']);

                foreach ($tripsData as $t) {
                    fputcsv($file, [
                        $t['date'],
                        $t['start_time'],
                        $t['end_time'],
                        $t['distance'],
                        $t['duration'],
                        $t['fuel_est'],
                        "http://maps.google.com/?q={$t['start_lat']},{$t['start_lon']}",
                        "http://maps.google.com/?q={$t['end_lat']},{$t['end_lon']}"
                    ]);
                }
                fclose($file);
            };
            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    } */
    public function exportTripsReport(Request $request, $deviceId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'format'     => 'nullable|in:csv,pdf'
        ]);

        try {
            $device = Device::findOrFail($deviceId);
            $format = $request->format ?? 'csv';
            $timezone = 'America/Mexico_City';

            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            // 1. OBTENER DATOS CRUDOS
            $locations = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$start, $end])
                ->where('latitude', '!=', 0)
                ->orderBy('timestamp', 'ASC')
                ->get();

            // 🔥 2. SANITIZACIÓN OBLIGATORIA (CORRECCIÓN KM FANTASMAS)
            // Antes de procesar rutas, corregimos cualquier punto que haya caído en China
            $locations->transform(function ($loc) {
                $lat = (float)$loc->latitude;
                $lon = (float)$loc->longitude;

                // Si la longitud es positiva (Asia) y debería ser negativa (América)
                if ($lon > 80 && $lon < 180) {
                    $lon = $lon * -1;
                }

                $loc->latitude = $lat;
                $loc->longitude = $lon;
                return $loc;
            });

            // 3. DETECTAR RUTAS (Con datos ya limpios)
            $routesRaw = $this->detectMultipleRoutes($locations, 10, $device);

            $tripsData = [];
            $summary = ['total_trips' => 0, 'total_km' => 0, 'total_time' => 0, 'max_speed' => 0];

            foreach ($routesRaw as $route) {
                $distKm = $route['statistics']['distance'] ?? 0;
                $durationSec = $route['statistics']['duration'] ?? 0;
                $maxSpeed = $route['statistics']['max_speed'] ?? 0;

                // Puntos Inicio/Fin
                $startPt = $route['points'][0];
                $endPt = end($route['points']);

                // Generar Link de Ruta (Direcciones)
                $routeLink = "https://www.google.com/maps/dir/?api=1&origin={$startPt['lat']},{$startPt['lon']}&destination={$endPt['lat']},{$endPt['lon']}";

                $trip = [
                    'start_time' => Carbon::parse($route['start_time'])->setTimezone($timezone)->format('H:i'),
                    'end_time'   => Carbon::parse($route['end_time'])->setTimezone($timezone)->format('H:i'),
                    'date'       => Carbon::parse($route['start_time'])->setTimezone($timezone)->format('d/m/Y'),
                    'distance'   => $distKm,
                    'duration'   => $route['statistics']['duration_human'],
                    'max_speed'  => $maxSpeed,
                    'fuel_est'   => round(($distKm / 10) * 24.50, 2), // Ajusta precio/rendimiento si lo tienes en BD

                    // Coordenadas
                    'start_lat'  => $startPt['lat'],
                    'start_lon'  => $startPt['lon'],
                    'end_lat'    => $endPt['lat'],
                    'end_lon'    => $endPt['lon'],
                    'route_link' => $routeLink
                ];

                // Filtro visual: Si por alguna razón extrema sigue dando > 2000km un solo viaje, lo mostramos pero lo marcamos
                if ($distKm < 2000) {
                    $tripsData[] = $trip;
                    $summary['total_trips']++;
                    $summary['total_km'] += $distKm;
                    $summary['total_time'] += $durationSec;
                    if ($maxSpeed > $summary['max_speed']) $summary['max_speed'] = $maxSpeed;
                }
            }

            if ($format === 'pdf') {
                $logoPath = public_path('images/logo.png');
                $logoData = null;
                if (file_exists($logoPath)) {
                    $type = pathinfo($logoPath, PATHINFO_EXTENSION);
                    $data = file_get_contents($logoPath);
                    $logoData = 'data:image/' . $type . ';base64,' . base64_encode($data);
                }

                $pdf = Pdf::loadView('reports.trips_pdf', [
                    'device' => $device,
                    'trips' => $tripsData,
                    'summary' => $summary,
                    'range' => $request->start_date . ' al ' . $request->end_date,
                    'logo' => $logoData,
                    'generated_at' => now()->setTimezone($timezone)->format('d/m/Y H:i')
                ]);

                return $pdf->setPaper('a4', 'landscape')->download("Viajes_{$device->name}.pdf");
            }

            // CSV (Lógica simplificada para ahorrar espacio en esta respuesta)
             // ==========================================
            // 🅱️ SALIDA CSV
            // ==========================================
            $fileName = "Viajes_{$device->name}.csv";
            $headers = ["Content-type" => "text/csv", "Content-Disposition" => "attachment; filename=$fileName", "Pragma" => "no-cache"];

            $callback = function () use ($tripsData, $device, $request) {
                $file = fopen('php://output', 'w');
                fputs($file, "\xEF\xBB\xBF");
                fputcsv($file, ['REPORTE DE VIAJES DETALLADO']);
                fputcsv($file, ['Unidad:', $device->name]);
                fputcsv($file, ['Periodo:', $request->start_date . ' - ' . $request->end_date]);
                fputcsv($file, []);
                fputcsv($file, ['Fecha', 'Hora Inicio', 'Hora Fin', 'Distancia (km)', 'Duración', 'Costo Est. ($)', 'Link Inicio', 'Link Fin']);

                foreach ($tripsData as $t) {
                    fputcsv($file, [
                        $t['date'],
                        $t['start_time'],
                        $t['end_time'],
                        $t['distance'],
                        $t['duration'],
                        $t['fuel_est'],
                        "http://maps.google.com/?q={$t['start_lat']},{$t['start_lon']}",
                        "http://maps.google.com/?q={$t['end_lat']},{$t['end_lon']}"
                    ]);
                }
                fclose($file);
            };
            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    private function minutesToHuman($minutes)
    {
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return ($h > 0 ? "{$h}h " : "") . "{$m}m";
    }
}
