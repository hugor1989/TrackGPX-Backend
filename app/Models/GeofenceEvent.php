<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo para eventos de geocercas
 */
class GeofenceEvent extends Model
{
    protected $fillable = [
        'geofence_id',
        'device_id',
        'event_type',
        'latitude',
        'longitude',
        'speed',
        'event_time',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed' => 'decimal:2',
        'event_time' => 'datetime',
    ];

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}