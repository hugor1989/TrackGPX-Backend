<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Geofence extends Model
{
    protected $fillable = [
        'device_id',
        'customer_id',
        'name',
        'type',
        'icon',
        'color',
        'center_lat',
        'center_lon',
        'radius',
        'polygon_points',
        'alert_on_enter',
        'alert_on_exit',
        'schedule_enabled',
        'schedule_days',
        'schedule_start',
        'schedule_end',
        'is_active',
    ];

    protected $casts = [
        'polygon_points' => 'array',
        'schedule_days' => 'array',
        'alert_on_enter' => 'boolean',
        'alert_on_exit' => 'boolean',
        'schedule_enabled' => 'boolean',
        'is_active' => 'boolean',
        'center_lat' => 'decimal:7',
        'center_lon' => 'decimal:7',
        'radius' => 'integer',
    ];

    /**
     * Relación con dispositivo
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Relación con cliente
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relación con eventos de geocerca
     */
    public function events(): HasMany
    {
        return $this->hasMany(GeofenceEvent::class);
    }

    /**
     * Verificar si un punto está dentro de la geocerca
     */
    public function containsPoint($lat, $lon): bool
    {
        if ($this->type === 'circle') {
            return $this->isPointInCircle($lat, $lon);
        } elseif ($this->type === 'polygon') {
            return $this->isPointInPolygon($lat, $lon);
        }
        return false;
    }

    /**
     * Verificar si un punto está dentro del círculo
     */
    private function isPointInCircle($lat, $lon): bool
    {
        $distance = $this->calculateDistance(
            $this->center_lat,
            $this->center_lon,
            $lat,
            $lon
        );
        
        return $distance <= $this->radius;
    }

    /**
     * Verificar si un punto está dentro del polígono (Ray Casting Algorithm)
     */
    private function isPointInPolygon($lat, $lon): bool
    {
        if (!$this->polygon_points || count($this->polygon_points) < 3) {
            return false;
        }

        $vertices = $this->polygon_points;
        $intersections = 0;
        $verticesCount = count($vertices);

        for ($i = 0; $i < $verticesCount; $i++) {
            $vertex1 = $vertices[$i];
            $vertex2 = $vertices[($i + 1) % $verticesCount];

            if ($vertex1['lat'] == $vertex2['lat']) {
                continue;
            }

            if ($lat < min($vertex1['lat'], $vertex2['lat'])) {
                continue;
            }

            if ($lat >= max($vertex1['lat'], $vertex2['lat'])) {
                continue;
            }

            $x = ($lat - $vertex1['lat']) * 
                 ($vertex2['lon'] - $vertex1['lon']) / 
                 ($vertex2['lat'] - $vertex1['lat']) + 
                 $vertex1['lon'];

            if ($x > $lon) {
                $intersections++;
            }
        }

        return ($intersections % 2) != 0;
    }

    /**
     * Calcular distancia entre dos puntos en metros
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000; // Radio de la Tierra en metros

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Scope para geocercas activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para geocercas de un dispositivo
     */
    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}



