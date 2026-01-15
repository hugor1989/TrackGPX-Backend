<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = [
        'vehicle_id',
        'imei',
        'protocol',
        'status',
        'last_connection',
        'customer_id',
        // Nuevos campos agregados por la migración
        'serial_number',
        'activation_code',
        'manufacturer',
        'model',
        'config_parameters',
        'sim_card_id', // ← AGREGAR ESTA LÍNEA
        'activated_at',

        // 👇 NUEVOS CAMPOS AGREGADOS
        'last_latitude',
        'last_longitude',
        'last_speed',
        'last_heading'
    ];

    protected $casts = [
        'last_connection' => 'datetime',
        'activated_at' => 'datetime',
        'config_parameters' => 'array'
    ];

    // Relaciones existentes
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    // Y agrega esta función al final de la clase:
    public function simCard()
    {
        return $this->belongsTo(SimCard::class, 'sim_card_id');
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function logs()
    {
        return $this->hasMany(DeviceLog::class);
    }

    /**
     * Relación con alarmas
     */
    public function alarms(): HasOne
    {
        return $this->hasOne(DeviceAlarm::class);
    }

    /**
     * Relación con comandos
     */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function sharedWith()
    {
        return $this->belongsToMany(Customer::class, 'device_customer');
    }

    public function owner()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Relación con el cliente (usuario)
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
    // Nueva relación para acceder al usuario a través del vehículo
    public function user()
    {
        return $this->hasOneThrough(Customer::class, Vehicle::class);
    }

    // app/Models/Device.php
    public function configuration()
    {
        return $this->hasOne(DeviceConfiguration::class);
    }

    // Accessor para obtener nombre personalizado o default
    public function getDisplayNameAttribute()
    {
        return $this->configuration?->custom_name ?? $this->vehicle_name ?? 'Dispositivo GPS';
    }

    // Scopes para filtros comunes
    public function scopePendingActivation($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }

    public function scopeWithSimCard($query)
    {
        return $query->whereHas('simCard');
    }

    public function scopeWithoutVehicle($query)
    {
        return $query->whereNull('vehicle_id');
    }

    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    // Métodos de negocio
    public function activate()
    {
        $this->update([
            'status' => 'active',
            'activated_at' => now()
        ]);

        return $this;
    }

    public function deactivate()
    {
        $this->update([
            'status' => 'inactive'
        ]);

        return $this;
    }

    public function markAsDisconnected()
    {
        $this->update([
            'status' => 'disconnected'
        ]);

        return $this;
    }

    public function markAsPending()
    {
        $this->update([
            'status' => 'pending'
        ]);

        return $this;
    }

    public function isActivable()
    {
        return $this->status === 'pending' && $this->vehicle_id && $this->activation_code;
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function generateActivationCode()
    {
        $this->activation_code = strtoupper(substr(md5(uniqid()), 0, 6));
        $this->save();

        return $this->activation_code;
    }

    public function updateLastConnection()
    {
        $this->update([
            'last_connection' => now(),
            'status' => 'active' // Se reactiva automáticamente al recibir conexión
        ]);

        return $this;
    }

    public function assignToVehicle($vehicleId)
    {
        $this->update([
            'vehicle_id' => $vehicleId
        ]);

        return $this;
    }

    public function getConfigParametersAttribute($value)
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode($value, true) ?? [
            'heartbeat_interval' => 60,
            'gps_interval' => 30,
            'overspeed_limit' => 80,
            'sos_numbers' => [],
            'geo_fences' => []
        ];
    }

    public function setConfigParametersAttribute($value)
    {
        $this->attributes['config_parameters'] = is_array($value)
            ? json_encode($value)
            : $value;
    }

    // Método para obtener datos para QR
    public function getQrData()
    {
        return [
            'imei' => $this->imei,
            'serial' => $this->serial_number,
            'activation_code' => $this->activation_code,
            'model' => $this->model
        ];
    }

    // Método para verificar si el dispositivo está comunicándose
    public function isOnline()
    {
        if (!$this->last_connection) {
            return false;
        }

        return $this->last_connection->diffInMinutes(now()) < 10; // Considerar online si se comunicó en los últimos 10 minutos
    }

    // Método para obtener el tiempo desde la última conexión
    public function getLastConnectionText()
    {
        if (!$this->last_connection) {
            return 'Nunca conectado';
        }

        return $this->last_connection->diffForHumans();
    }
}
