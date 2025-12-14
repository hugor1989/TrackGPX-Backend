<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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