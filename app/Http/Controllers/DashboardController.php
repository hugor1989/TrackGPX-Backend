<?php
// app/Http/Controllers/DashboardController.php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Location;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Obtener estadísticas generales del dashboard
     */
    public function getStatistics(Request $request)
    {
        $user = Auth::user();
        $dateRange = $request->input('date_range', '7days');
        
        [$startDate, $endDate] = $this->getDateRange($dateRange, $request);
        
        // Obtener todos los dispositivos del usuario
        $devices = Device::where('customer_id', $user->id)->get();
        
        if ($devices->isEmpty()) {
            return response()->json([
                'success' => true,
                'statistics' => [
                    'total_distance' => 0,
                    'avg_activity' => 0,
                    'active_devices' => 0,
                    'total_alerts' => 0,
                    'total_devices' => 0,
                    'low_battery_count' => 0,
                ],
                'date_range' => $dateRange,
            ]);
        }
        
        $deviceIds = $devices->pluck('id');
        
        // Calcular distancia total
        $totalDistance = 0;
        $totalActivity = 0;
        
        foreach ($devices as $device) {
            $locations = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$startDate, $endDate])
                ->orderBy('timestamp', 'asc')
                ->get();
            
            $totalDistance += $this->calculateDistance($locations);
            $totalActivity += $this->calculateActivity($locations);
        }
        
        $avgActivity = $devices->count() > 0 ? round($totalActivity / $devices->count()) : 0;
        
        // Dispositivos activos (última ubicación < 15 min)
        $activeDevices = Device::where('customer_id', $user->id)
            ->where('status', 'active')
            ->whereHas('locations', function($query) {
                $query->where('timestamp', '>=', Carbon::now()->subMinutes(15));
            })
            ->count();
        
        // Total de alertas del período
        $totalAlerts = Notification::where('customer_id', $user->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('type', ['speed_alert', 'low_battery', 'removal_alert'])
            ->count();
        
        // Dispositivos con batería baja
        $lowBatteryCount = 0;
        foreach ($devices as $device) {
            $lastLocation = Location::where('device_id', $device->id)
                ->orderBy('timestamp', 'desc')
                ->first();
            
            if ($lastLocation && $lastLocation->battery_level < 30) {
                $lowBatteryCount++;
            }
        }
        
        return response()->json([
            'success' => true,
            'statistics' => [
                'total_distance' => round($totalDistance, 2),
                'avg_activity' => $avgActivity,
                'active_devices' => $activeDevices,
                'total_alerts' => $totalAlerts,
                'total_devices' => $devices->count(),
                'low_battery_count' => $lowBatteryCount,
            ],
            'date_range' => $dateRange,
        ]);
    }
    
    /**
     * Obtener lista de dispositivos con métricas
     */
    public function getDevices(Request $request)
    {
        $user = Auth::user();
        $dateRange = $request->input('date_range', '7days');
        $status = $request->input('status', 'all');
        $sortBy = $request->input('sort_by', 'name');
        
        [$startDate, $endDate] = $this->getDateRange($dateRange, $request);
        
        // Query base
        $query = Device::where('customer_id', $user->id)
            ->with(['vehicle']);
        
        // Filtrar por estado
        if ($status !== 'all' && $status !== 'low_battery') {
            $query->where('status', $status);
        }
        
        $devices = $query->get()->map(function($device) use ($startDate, $endDate, $user) {
            // Obtener ubicaciones del período
            $locations = Location::where('device_id', $device->id)
                ->whereBetween('timestamp', [$startDate, $endDate])
                ->orderBy('timestamp', 'asc')
                ->get();
            
            // Última ubicación
            $lastLocation = Location::where('device_id', $device->id)
                ->orderBy('timestamp', 'desc')
                ->first();
            
            // Calcular métricas
            $distance = $this->calculateDistance($locations);
            $activity = $this->calculateActivity($locations);
            $maxSpeed = $locations->max('speed') ?? 0;
            
            // Batería actual
            $battery = $lastLocation ? $lastLocation->battery_level ?? 0 : 0;
            
            // Velocidad actual
            $currentSpeed = $lastLocation ? $lastLocation->speed ?? 0 : 0;
            
            // Contar alertas del período
            $alerts = Notification::where('customer_id', $user->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereIn('type', ['speed_alert', 'low_battery', 'removal_alert'])
                ->where(function($query) use ($device) {
                    $query->whereJsonContains('data->device_id', $device->id)
                          ->orWhereJsonContains('data->device_id', (string)$device->id);
                })
                ->count();
            
            // Determinar estado actual
            $isActive = $lastLocation && 
                        Carbon::parse($lastLocation->timestamp)->diffInMinutes(Carbon::now()) < 15;
            
            return [
                'id' => $device->id,
                'imei' => $device->imei,
                'name' => $device->vehicle?->alias ?? $device->vehicle?->plates ?? $device->imei,
                'vehicle_id' => $device->vehicle_id,
                'status' => $isActive ? 'active' : 'inactive',
                'last_update' => $this->getRelativeTime($lastLocation?->timestamp),
                'last_update_timestamp' => $lastLocation?->timestamp,
                
                // Métricas calculadas
                'distance' => round($distance, 2),
                'activity' => round($activity),
                'battery' => $battery,
                'speed' => round($currentSpeed, 2),
                'alerts' => $alerts,
                
                // Info del vehículo
                'vehicle' => $device->vehicle ? [
                    'id' => $device->vehicle->id,
                    'plates' => $device->vehicle->plates,
                    'alias' => $device->vehicle->alias,
                    'brand' => $device->vehicle->brand ?? null,
                    'model' => $device->vehicle->model ?? null,
                    'year' => $device->vehicle->year ?? null,
                ] : null,
            ];
        });
        
        // Filtrar por batería baja si es necesario
        if ($status === 'low_battery') {
            $devices = $devices->filter(fn($d) => $d['battery'] < 30);
        }
        
        // Ordenar
        $devices = $this->sortDevices($devices, $sortBy);
        
        return response()->json([
            'success' => true,
            'devices' => $devices->values(),
        ]);
    }
    
    /**
     * Obtener detalles de un dispositivo específico
     */
    public function getDeviceDetails(Request $request, $deviceId)
    {
        $user = Auth::user();
        $dateRange = $request->input('date_range', '7days');
        
        [$startDate, $endDate] = $this->getDateRange($dateRange, $request);
        
        $device = Device::where('customer_id', $user->id)
            ->where('id', $deviceId)
            ->with(['vehicle'])
            ->firstOrFail();
        
        // Obtener ubicaciones del período
        $locations = Location::where('device_id', $device->id)
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->orderBy('timestamp', 'asc')
            ->get();
        
        // Última ubicación
        $lastLocation = Location::where('device_id', $device->id)
            ->orderBy('timestamp', 'desc')
            ->first();
        
        // Calcular métricas
        $distance = $this->calculateDistance($locations);
        $activity = $this->calculateActivity($locations);
        [$movementTime, $stoppedTime] = $this->calculateMovementTime($locations);
        
        $speeds = $locations->pluck('speed')->filter(fn($s) => $s > 0);
        $maxSpeed = $speeds->max() ?? 0;
        $avgSpeed = $speeds->avg() ?? 0;
        
        // Contar alertas
        $alerts = Notification::where('customer_id', $user->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where(function($query) use ($device) {
                $query->whereJsonContains('data->device_id', $device->id)
                      ->orWhereJsonContains('data->device_id', (string)$device->id);
            })
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'created_at' => $notification->created_at,
                ];
            });
        
        return response()->json([
            'success' => true,
            'device' => [
                'id' => $device->id,
                'imei' => $device->imei,
                'name' => $device->vehicle?->alias ?? $device->vehicle?->plates ?? $device->imei,
                'status' => $device->status,
                'last_update' => $this->getRelativeTime($lastLocation?->timestamp),
                
                // Métricas
                'metrics' => [
                    'distance' => round($distance, 2),
                    'activity' => round($activity),
                    'battery' => $lastLocation?->battery_level ?? 0,
                    'current_speed' => $lastLocation?->speed ?? 0,
                    'max_speed' => round($maxSpeed, 2),
                    'avg_speed' => round($avgSpeed, 2),
                    'total_alerts' => $alerts->count(),
                    'movement_time' => $this->formatMinutes($movementTime),
                    'stopped_time' => $this->formatMinutes($stoppedTime),
                ],
                
                // Ubicación actual
                'current_location' => $lastLocation ? [
                    'latitude' => (float)$lastLocation->latitude,
                    'longitude' => (float)$lastLocation->longitude,
                    'timestamp' => $lastLocation->timestamp,
                ] : null,
                
                // Alertas
                'alerts' => $alerts,
            ],
        ]);
    }
    
    /**
     * Calcular distancia total usando Haversine
     */
    private function calculateDistance($locations)
    {
        if ($locations->count() < 2) {
            return 0;
        }
        
        $totalDistance = 0;
        
        for ($i = 1; $i < $locations->count(); $i++) {
            $prev = $locations[$i - 1];
            $curr = $locations[$i];
            
            $distance = $this->haversineDistance(
                (float)$prev->latitude,
                (float)$prev->longitude,
                (float)$curr->latitude,
                (float)$curr->longitude
            );
            
            // Solo sumar si la distancia es razonable (menos de 5km entre puntos)
            if ($distance < 5 && $distance > 0) {
                $totalDistance += $distance;
            }
        }
        
        return $totalDistance;
    }
    
    /**
     * Fórmula de Haversine
     */
    private function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * Calcular porcentaje de actividad
     */
    private function calculateActivity($locations)
    {
        if ($locations->count() < 2) {
            return 0;
        }
        
        $movementMinutes = 0;
        $stoppedMinutes = 0;
        $speedThreshold = 5; // km/h
        
        for ($i = 1; $i < $locations->count(); $i++) {
            $prev = $locations[$i - 1];
            $curr = $locations[$i];
            
            $timeDiff = Carbon::parse($prev->timestamp)
                ->diffInMinutes(Carbon::parse($curr->timestamp));
            
            // Ignorar gaps muy grandes (más de 30 minutos)
            if ($timeDiff > 30) {
                continue;
            }
            
            if (($prev->speed ?? 0) >= $speedThreshold) {
                $movementMinutes += $timeDiff;
            } else {
                $stoppedMinutes += $timeDiff;
            }
        }
        
        $totalMinutes = $movementMinutes + $stoppedMinutes;
        
        return $totalMinutes > 0 ? ($movementMinutes / $totalMinutes) * 100 : 0;
    }
    
    /**
     * Calcular tiempo en movimiento vs detenido
     */
    private function calculateMovementTime($locations)
    {
        if ($locations->count() < 2) {
            return [0, 0];
        }
        
        $movementMinutes = 0;
        $stoppedMinutes = 0;
        $speedThreshold = 5; // km/h
        
        for ($i = 1; $i < $locations->count(); $i++) {
            $prev = $locations[$i - 1];
            $curr = $locations[$i];
            
            $timeDiff = Carbon::parse($prev->timestamp)
                ->diffInMinutes(Carbon::parse($curr->timestamp));
            
            if ($timeDiff > 30) {
                continue;
            }
            
            if (($prev->speed ?? 0) >= $speedThreshold) {
                $movementMinutes += $timeDiff;
            } else {
                $stoppedMinutes += $timeDiff;
            }
        }
        
        return [$movementMinutes, $stoppedMinutes];
    }
    
    /**
     * Obtener rango de fechas
     */
    private function getDateRange($dateRange, $request)
    {
        switch ($dateRange) {
            case 'today':
                $startDate = Carbon::today();
                $endDate = Carbon::now();
                break;
            case '7days':
                $startDate = Carbon::now()->subDays(7);
                $endDate = Carbon::now();
                break;
            case '30days':
                $startDate = Carbon::now()->subDays(30);
                $endDate = Carbon::now();
                break;
            case 'custom':
                $startDate = Carbon::parse($request->input('start_date'));
                $endDate = Carbon::parse($request->input('end_date'));
                break;
            default:
                $startDate = Carbon::now()->subDays(7);
                $endDate = Carbon::now();
        }
        
        return [$startDate, $endDate];
    }
    
    /**
     * Tiempo relativo
     */
    private function getRelativeTime($timestamp)
    {
        if (!$timestamp) return 'Sin datos';
        
        $carbon = Carbon::parse($timestamp);
        $diffInMinutes = $carbon->diffInMinutes(Carbon::now());
        
        if ($diffInMinutes < 1) return 'Justo ahora';
        if ($diffInMinutes < 60) return "{$diffInMinutes} min";
        if ($diffInMinutes < 1440) return round($diffInMinutes / 60) . " h";
        return round($diffInMinutes / 1440) . " días";
    }
    
    /**
     * Formatear minutos
     */
    private function formatMinutes($minutes)
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return "{$hours}h {$mins}m";
    }
    
    /**
     * Ordenar dispositivos
     */
    private function sortDevices($devices, $sortBy)
    {
        return $devices->sortBy(function($device) use ($sortBy) {
            switch ($sortBy) {
                case 'name':
                    return $device['name'];
                case 'distance':
                    return -$device['distance'];
                case 'battery':
                    return $device['battery'];
                case 'activity':
                    return -$device['activity'];
                default:
                    return $device['name'];
            }
        });
    }
}