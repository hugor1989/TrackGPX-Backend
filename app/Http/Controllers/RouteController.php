<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

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

            // Consulta base
            $query = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$startDate, $endDate])
                ->where('latitude', '!=', 0)
                ->where('longitude', '!=', 0)
                ->orderBy('timestamp', 'ASC');

            // Aplicar filtros
            if ($minSpeed > 0) $query->where('speed', '>=', $minSpeed);
            if ($maxSpeed < 200) $query->where('speed', '<=', $maxSpeed);
            if ($minBattery > 0) $query->where('battery_level', '>=', $minBattery);

            $locations = $query->get();

            \Log::info('📍 Ubicaciones encontradas:', [
                'count' => $locations->count(),
                'first_location' => $locations->first() ? [
                    'lat' => $locations->first()->latitude,
                    'lon' => $locations->first()->longitude,
                    'timestamp' => $locations->first()->timestamp,
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

                \Log::info('🛣️ Rutas detectadas:', [
                    'total_routes' => count($routes),
                    'routes_summary' => array_map(function ($route) {
                        return [
                            'id' => $route['id'],
                            'points' => count($route['points']),
                            'start' => $route['start_time'],
                            'end' => $route['end_time'],
                        ];
                    }, $routes)
                ]);

                // Aplicar compresión a cada ruta si se solicita
                if ($request->compress) {
                    $compressFactor = $request->compress_factor ?? 10;
                    foreach ($routes as &$route) {
                        $route['points'] = $this->compressPoints($route['points'], $compressFactor);
                        $route['statistics']['total_points'] = count($route['points']);
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
                $route['points'] = $this->compressPoints($route['points'], $compressFactor);
                $route['statistics']['total_points'] = count($route['points']);
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
            \Log::error('❌ Error en getRouteByDate:', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
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

        \Log::info('🔍 Iniciando detección de rutas', [
            'total_locations' => $locations->count(),
            'max_interval_minutes' => $maxIntervalMinutes,
            'max_interval_seconds' => $maxIntervalSeconds
        ]);

        foreach ($locations as $index => $location) {
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

            $currentTimestamp = Carbon::parse($location->timestamp);

            // Si es el primer punto, iniciar nueva ruta
            if ($previousTimestamp === null) {
                $routeIndex++;
                $currentRoute = [
                    'id' => $routeIndex,
                    'points' => [$currentPoint],
                    'start_time' => $location->timestamp->toISOString(),
                    'end_time' => $location->timestamp->toISOString(),
                ];

                \Log::info("🆕 Ruta #{$routeIndex} iniciada (primer punto)", [
                    'timestamp' => $currentTimestamp->toISOString(),
                    'index' => $index
                ]);
            } else {
                // Calcular diferencia de tiempo con el punto anterior
                $timeDiff = $currentTimestamp->diffInSeconds($previousTimestamp);

                \Log::info("⏱️ Analizando punto #{$index}", [
                    'current_time' => $currentTimestamp->toISOString(),
                    'previous_time' => $previousTimestamp->toISOString(),
                    'time_diff_seconds' => $timeDiff,
                    'max_allowed_seconds' => $maxIntervalSeconds,
                    'exceeds_limit' => $timeDiff > $maxIntervalSeconds
                ]);

                // 🔥 CORRECCIÓN CRÍTICA: Si el intervalo excede el límite, finalizar ruta actual
                if ($timeDiff > $maxIntervalSeconds) {
                    // Finalizar ruta anterior
                    if ($currentRoute && count($currentRoute['points']) > 0) {
                        $currentRoute = $this->calculateRouteStatistics($currentRoute, $device);
                        $routes[] = $currentRoute;

                        \Log::info("✅ Ruta #{$currentRoute['id']} finalizada", [
                            'points' => count($currentRoute['points']),
                            'start' => $currentRoute['start_time'],
                            'end' => $currentRoute['end_time'],
                            'reason' => "Intervalo de {$timeDiff}s excede límite de {$maxIntervalSeconds}s"
                        ]);
                    }

                    // Iniciar NUEVA ruta con el punto actual
                    $routeIndex++;
                    $currentRoute = [
                        'id' => $routeIndex,
                        'points' => [$currentPoint],
                        'start_time' => $location->timestamp->toISOString(),
                        'end_time' => $location->timestamp->toISOString(),
                    ];

                    \Log::info("🆕 Ruta #{$routeIndex} iniciada (por intervalo)", [
                        'timestamp' => $currentTimestamp->toISOString(),
                        'previous_route_ended' => $previousTimestamp->toISOString(),
                        'gap_seconds' => $timeDiff
                    ]);
                } else {
                    // Agregar punto a la ruta actual
                    $currentRoute['points'][] = $currentPoint;
                    $currentRoute['end_time'] = $location->timestamp->toISOString();

                    \Log::info("➕ Punto agregado a ruta #{$currentRoute['id']}", [
                        'total_points' => count($currentRoute['points']),
                        'time_diff' => $timeDiff
                    ]);
                }
            }

            $previousTimestamp = $currentTimestamp;
        }

        // Agregar la última ruta si existe
        if ($currentRoute && count($currentRoute['points']) > 0) {
            $currentRoute = $this->calculateRouteStatistics($currentRoute, $device);
            $routes[] = $currentRoute;

            \Log::info("✅ Última ruta #{$currentRoute['id']} finalizada", [
                'points' => count($currentRoute['points']),
                'start' => $currentRoute['start_time'],
                'end' => $currentRoute['end_time']
            ]);
        }

        \Log::info('🏁 Detección completada', [
            'total_routes' => count($routes),
            'routes' => array_map(function ($r) {
                return [
                    'id' => $r['id'],
                    'points' => count($r['points']),
                    'duration' => $r['statistics']['duration_human']
                ];
            }, $routes)
        ]);

        return $routes;
    }

    /**
     * Calcular estadísticas para una ruta
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
            ];
            return $route;
        }

        // Calcular distancia, duración y estadísticas
        $totalDistance = 0;
        $totalDuration = 0;
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

            // Calcular duración
            $duration = Carbon::parse($current['timestamp'])
                ->diffInSeconds(Carbon::parse($prev['timestamp']));
            $totalDuration += $duration;

            // Recolectar datos para promedios
            if ($current['speed'] !== null) $speeds[] = $current['speed'];
            if ($current['battery'] !== null) $batteries[] = $current['battery'];
            if ($current['altitude'] !== null) $altitudes[] = $current['altitude'];
        }

        // Convertir distancia a km
        $totalDistanceKm = $totalDistance / 1000;

        // Calcular promedios
        $avgSpeed = count($speeds) > 0 ? array_sum($speeds) / count($speeds) : 0;
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
        ];

        $route['device_name'] = $device ? $device->name : 'Dispositivo';
        $route['device_id'] = $device ? $device->id : 0;

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
}
