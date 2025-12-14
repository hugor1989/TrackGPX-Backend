<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Validator;

class RouteController extends Controller
{
    // Obtener rutas disponibles por fechas
    public function getDeviceRoutes(Request $request, $deviceId)
    {
        try {
            $device = Device::findOrFail($deviceId);

            // Obtener fechas con datos disponibles (últimos 30 días)
            $dates = Location::where('device_id', $device->id)
                ->where('timestamp', '>=', Carbon::now()->subDays(30))
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

    // Obtener ruta por rango de fechas - ADAPTADO A TU ESTRUCTURA
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
            $maxSpeed = $request->max_speed ?? 200; // km/h máximo
            $minBattery = $request->min_battery ?? 0;

            // Consulta base con TU estructura de tabla
            $query = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$startDate, $endDate])
                ->orderBy('timestamp', 'ASC');

            // Filtrar por velocidad
            if ($minSpeed > 0) {
                $query->where('speed', '>=', $minSpeed);
            }
            if ($maxSpeed < 200) {
                $query->where('speed', '<=', $maxSpeed);
            }

            // Filtrar por batería mínima
            if ($minBattery > 0) {
                $query->where('battery_level', '>=', $minBattery);
            }

            $locations = $query->get();

            // Si no hay datos
            if ($locations->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'route' => [
                        'device_id' => $device->id,
                        'device_name' => $device->name,
                        'start_date' => $startDate->toISOString(),
                        'end_date' => $endDate->toISOString(),
                        'points' => [],
                        'statistics' => [
                            'total_points' => 0,
                            'distance' => 0,
                            'duration' => 0,
                            'avg_speed' => 0,
                            'max_speed' => 0,
                            'min_speed' => 0,
                            'avg_battery' => 0,
                            'min_battery' => 0,
                            'max_battery' => 0,
                            'avg_altitude' => 0,
                        ],
                        'filters_applied' => [
                            'min_speed' => $minSpeed,
                            'max_speed' => $maxSpeed,
                            'min_battery' => $minBattery,
                        ]
                    ],
                    'message' => 'No se encontraron puntos en el rango de fechas'
                ]);
            }

            // Comprimir puntos si se solicita
            if ($request->compress) {
                $compressFactor = $request->compress_factor ?? 10; // Tomar 1 de cada 10 puntos
                $compressedLocations = $locations->filter(function ($item, $index) use ($compressFactor) {
                    return $index % $compressFactor === 0;
                });
                $locations = $compressedLocations->values();
            }

            // Calcular estadísticas
            $totalDistance = 0;
            $totalDuration = 0;
            $speeds = [];
            $batteries = [];
            $altitudes = [];

            for ($i = 1; $i < count($locations); $i++) {
                $prev = $locations[$i - 1];
                $current = $locations[$i];

                // Calcular distancia entre puntos (en metros)
                $distance = $this->calculateHaversineDistance(
                    $prev->latitude,
                    $prev->longitude,
                    $current->latitude,
                    $current->longitude
                );
                $totalDistance += $distance;

                // Calcular duración (en segundos)
                $duration = Carbon::parse($current->timestamp)
                    ->diffInSeconds(Carbon::parse($prev->timestamp));
                $totalDuration += $duration;

                // Recolectar datos para promedios
                if ($current->speed !== null) {
                    $speeds[] = $current->speed;
                }

                if ($current->battery_level !== null) {
                    $batteries[] = $current->battery_level;
                }

                if ($current->altitude !== null) {
                    $altitudes[] = $current->altitude;
                }
            }

            // Convertir distancia a kilómetros
            $totalDistanceKm = $totalDistance / 1000;

            // Calcular promedios
            $avgSpeed = count($speeds) > 0 ? array_sum($speeds) / count($speeds) : 0;
            $avgBattery = count($batteries) > 0 ? array_sum($batteries) / count($batteries) : null;
            $avgAltitude = count($altitudes) > 0 ? array_sum($altitudes) / count($altitudes) : null;

            // Formatear puntos para el frontend
            $points = $locations->map(function ($location) {
                return [
                    'lat' => (float) $location->latitude,
                    'lon' => (float) $location->longitude,
                    'speed' => $location->speed !== null ? (float) $location->speed : null,
                    'altitude' => $location->altitude !== null ? (float) $location->altitude : null,
                    'battery' => $location->battery_level !== null ? (int) $location->battery_level : null,
                    'timestamp' => $location->timestamp->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'route' => [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'start_date' => $startDate->toISOString(),
                    'end_date' => $endDate->toISOString(),
                    'points' => $points,
                    'statistics' => [
                        'total_points' => count($points),
                        'distance' => round($totalDistanceKm, 2), // km
                        'duration' => $totalDuration, // segundos
                        'duration_human' => $this->secondsToHuman($totalDuration),
                        'avg_speed' => round($avgSpeed, 2), // km/h
                        'max_speed' => count($speeds) > 0 ? max($speeds) : 0,
                        'min_speed' => count($speeds) > 0 ? min($speeds) : 0,
                        'avg_battery' => $avgBattery !== null ? round($avgBattery, 1) : null,
                        'min_battery' => count($batteries) > 0 ? min($batteries) : null,
                        'max_battery' => count($batteries) > 0 ? max($batteries) : null,
                        'avg_altitude' => $avgAltitude !== null ? round($avgAltitude, 1) : null,
                        'first_point_time' => $locations->first()->timestamp->toISOString(),
                        'last_point_time' => $locations->last()->timestamp->toISOString(),
                    ],
                    'metadata' => [
                        'compressed' => $request->compress ?? false,
                        'compress_factor' => $request->compress_factor ?? null,
                        'filters' => [
                            'min_speed' => $minSpeed,
                            'max_speed' => $maxSpeed,
                            'min_battery' => $minBattery,
                        ],
                        'query_time' => Carbon::now()->toISOString(),
                    ]
                ],
                'message' => 'Ruta obtenida correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ruta: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }

    // Función para calcular distancia Haversine (más precisa)
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

    // Convertir segundos a formato humano
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

            $days = $request->days ?? 7; // Últimos 7 días por defecto

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

                    // Calcular distancia aproximada (basada en puntos consecutivos)
                    // Nota: Para distancia real necesitarías cálculo punto por punto

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

    // Exportar ruta a GPX/KML - ACTUALIZADO
    public function exportRoute(Request $request, $deviceId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:gpx,kml,json',
            'include_metadata' => 'boolean',
            'simplify' => 'boolean', // Simplificar ruta para reducir tamaño
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

            // Primero obtenemos la ruta
            $routeRequest = new Request([
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'compress' => $request->simplify ?? true,
                'compress_factor' => $request->simplify ? 5 : 1, // 1 de cada 5 puntos si se simplifica
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

            // Generar archivo según formato
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
                // JSON format
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

            // Devolver como descarga
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
    <desc>Ruta del ' . $route['start_date'] . ' al ' . $route['end_date'] . '</desc>
    <time>' . now()->toISOString() . '</time>
    <bounds minlat="' . min(array_column($route['points'], 'lat')) . '" 
            minlon="' . min(array_column($route['points'], 'lon')) . '" 
            maxlat="' . max(array_column($route['points'], 'lat')) . '" 
            maxlon="' . max(array_column($route['points'], 'lon')) . '"/>
  </metadata>';
        }

        $gpx .= '
  <trk>
    <name>' . htmlspecialchars($device->name) . ' - ' .
            Carbon::parse($route['start_date'])->format('d/m/Y') . '</name>
    <desc>Distancia: ' . $route['statistics']['distance'] . ' km, Duración: ' .
            $route['statistics']['duration_human'] . '</desc>
    <trkseg>';

        foreach ($route['points'] as $point) {
            $gpx .= '
      <trkpt lat="' . $point['lat'] . '" lon="' . $point['lon'] . '">';

            if ($point['altitude'] !== null) {
                $gpx .= '
        <ele>' . $point['altitude'] . '</ele>';
            }

            $gpx .= '
        <time>' . $point['timestamp'] . '</time>';

            if ($point['speed'] !== null) {
                // Convertir km/h a m/s para GPX
                $speedMs = $point['speed'] / 3.6;
                $gpx .= '
        <speed>' . $speedMs . '</speed>';
            }

            $gpx .= '
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
<kml xmlns="http://www.opengis.net/kml/2.2">';

        if ($includeMetadata) {
            $kml .= '
  <Document>
    <name>Ruta - ' . htmlspecialchars($device->name) . '</name>
    <description><![CDATA[
      <h3>Ruta del dispositivo: ' . htmlspecialchars($device->name) . '</h3>
      <p><strong>Período:</strong> ' . Carbon::parse($route['start_date'])->format('d/m/Y H:i') .
                ' a ' . Carbon::parse($route['end_date'])->format('d/m/Y H:i') . '</p>
      <p><strong>Distancia:</strong> ' . $route['statistics']['distance'] . ' km</p>
      <p><strong>Duración:</strong> ' . $route['statistics']['duration_human'] . '</p>
      <p><strong>Velocidad promedio:</strong> ' . $route['statistics']['avg_speed'] . ' km/h</p>
      <p><strong>Puntos totales:</strong> ' . $route['statistics']['total_points'] . '</p>
    ]]></description>';
        } else {
            $kml .= '
  <Document>
    <name>' . htmlspecialchars($device->name) . '</name>';
        }

        $kml .= '
    <Style id="trackStyle">
      <LineStyle>
        <color>ff0078ff</color>
        <width>3</width>
      </LineStyle>
      <PolyStyle>
        <color>7f00ff00</color>
      </PolyStyle>
    </Style>
    <Placemark>
      <name>Ruta completa</name>
      <description>Ruta del dispositivo ' . htmlspecialchars($device->name) . '</description>
      <styleUrl>#trackStyle</styleUrl>
      <LineString>
        <tessellate>1</tessellate>
        <altitudeMode>clampToGround</altitudeMode>
        <coordinates>';

        foreach ($route['points'] as $point) {
            $kml .= $point['lon'] . ',' . $point['lat'] . ',' . ($point['altitude'] ?? 0) . ' ';
        }

        $kml .= '</coordinates>
      </LineString>
    </Placemark>
    
    <!-- Punto de inicio -->
    <Placemark>
      <name>Inicio</name>
      <description>Punto de inicio de la ruta</description>
      <Style>
        <IconStyle>
          <color>ff00ff00</color>
          <scale>1.2</scale>
          <Icon>
            <href>http://maps.google.com/mapfiles/kml/pushpin/grn-pushpin.png</href>
          </Icon>
        </IconStyle>
      </Style>
      <Point>
        <coordinates>' . $route['points'][0]['lon'] . ',' . $route['points'][0]['lat'] . ',0</coordinates>
      </Point>
    </Placemark>
    
    <!-- Punto final -->
    <Placemark>
      <name>Fin</name>
      <description>Punto final de la ruta</description>
      <Style>
        <IconStyle>
          <color>ffff0000</color>
          <scale>1.2</scale>
          <Icon>
            <href>http://maps.google.com/mapfiles/kml/pushpin/red-pushpin.png</href>
          </Icon>
        </IconStyle>
      </Style>
      <Point>
        <coordinates>' .
            $route['points'][count($route['points']) - 1]['lon'] . ',' .
            $route['points'][count($route['points']) - 1]['lat'] . ',0</coordinates>
      </Point>
    </Placemark>
  </Document>
</kml>';

        return $kml;
    }
}
