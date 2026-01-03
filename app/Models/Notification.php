<?php
// app/Models/Notification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'customer_id',
        'event_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
        'push_sent',
        'push_sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'push_sent' => 'boolean',
        'read_at' => 'datetime',
        'push_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Marcar como leída
     */
    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Marcar como enviada (push)
     */
    public function markAsPushSent()
    {
        if (!$this->push_sent) {
            $this->update([
                'push_sent' => true,
                'push_sent_at' => now(),
            ]);
        }
    }

    /**
     * Scope para notificaciones no leídas
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope para notificaciones leídas
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Scope por tipo
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope para notificaciones push enviadas
     */
    public function scopePushSent($query)
    {
        return $query->where('push_sent', true);
    }

    /**
     * Scope para notificaciones push no enviadas
     */
    public function scopePushNotSent($query)
    {
        return $query->where('push_sent', false);
    }
}