<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model: DeviceAlarm
 * Configuración de alarmas por dispositivo
 */
class DeviceAlarm extends Model
{
    protected $fillable = [
        'device_id',
        'alarm_removal',
        'alarm_low_battery',
        'alarm_vibration',
        'alarm_speed',
        'speed_limit',
        'alarm_geofence',
    ];

    protected $casts = [
        'alarm_removal' => 'boolean',
        'alarm_low_battery' => 'boolean',
        'alarm_vibration' => 'boolean',
        'alarm_speed' => 'boolean',
        'alarm_geofence' => 'boolean',
        'speed_limit' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}

/**
 * Model: DeviceCommand
 * Historial de comandos enviados a dispositivos
 */
class DeviceCommand extends Model
{
    protected $fillable = [
        'device_id',
        'command',
        'parameters',
        'status',
        'sent_at',
        'executed_at',
        'response',
    ];

    protected $casts = [
        'parameters' => 'array',
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Scope para comandos pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Marcar comando como ejecutado
     */
    public function markAsExecuted($response = null)
    {
        $this->update([
            'status' => 'executed',
            'executed_at' => now(),
            'response' => $response,
        ]);
    }

    /**
     * Marcar comando como fallido
     */
    public function markAsFailed($response = null)
    {
        $this->update([
            'status' => 'failed',
            'response' => $response,
        ]);
    }
}

/**
 * Model: GpsLocation
 * Historial de ubicaciones GPS
 */
class GpsLocation extends Model
{
    protected $fillable = [
        'device_id',
        'latitude',
        'longitude',
        'speed',
        'battery',
        'altitude',
        'heading',
        'accuracy',
        'satellites',
        'timestamp',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed' => 'decimal:2',
        'battery' => 'integer',
        'altitude' => 'decimal:2',
        'heading' => 'integer',
        'accuracy' => 'integer',
        'satellites' => 'integer',
        'timestamp' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}

