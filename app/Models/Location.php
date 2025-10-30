<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Location extends Model
{
    protected $fillable = [
        'device_id', 
        'latitude', 
        'longitude', 
        'speed', 
        'battery_level',
        'altitude', 
        'timestamp'
    ];

    public $timestamps = false;

    // Convertir el timestamp a la zona horaria correcta al guardar
    public function setTimestampAttribute($value)
    {
        // Si viene el timestamp del dispositivo, usarlo y convertir a México
        if ($value) {
            $this->attributes['timestamp'] = Carbon::parse($value)->setTimezone('America/Mexico_City');
        } else {
            $this->attributes['timestamp'] = Carbon::now('America/Mexico_City');
        }
    }

    // Convertir a la zona horaria correcta al leer
    public function getTimestampAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('America/Mexico_City');
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}