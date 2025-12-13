<?php


// app/Models/DeviceConfiguration.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceConfiguration extends Model
{
    protected $fillable = [
        'device_id',
        'custom_name',
        'color',
        'marker_icon',
        'vehicle_image',
        'route_type',
        'tracking_disabled',
        'sharing_enabled',
        'show_live_position',
        'show_pause_markers',
        'show_alerts',
        'fixed_date_range',
        'date_range_from',
        'date_range_to',
    ];

    protected $casts = [
        'tracking_disabled' => 'boolean',
        'sharing_enabled' => 'boolean',
        'show_live_position' => 'boolean',
        'show_pause_markers' => 'boolean',
        'show_alerts' => 'boolean',
        'fixed_date_range' => 'boolean',
        'date_range_from' => 'datetime',
        'date_range_to' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}