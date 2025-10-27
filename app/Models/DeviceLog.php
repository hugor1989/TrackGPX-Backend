<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLog extends Model
{
    protected $fillable = [
        'device_id',
        'action', 
        'description',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relación con el dispositivo
    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    // Scopes útiles
    public function scopeActivation($query)
    {
        return $query->where('action', 'activation');
    }

    public function scopeDeactivation($query)
    {
        return $query->where('action', 'deactivation');
    }

    public function scopeConfiguration($query)
    {
        return $query->where('action', 'configuration');
    }
}