<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceShare extends Model
{
    protected $fillable = ['device_id', 'token', 'expires_at', 'is_active'];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
    
    // Helper para saber si sigue válido
    public function isValid()
    {
        return $this->is_active && $this->expires_at->isFuture();
    }
}