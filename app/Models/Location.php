<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $table = 'locations';
    
    protected $fillable = [
        'device_id', 
        'latitude', 
        'longitude', 
        'speed', 
        'battery_level', // Agregar este campo
        'altitude', 
        'timestamp'
    ];

    // Si tu tabla tiene un nombre diferente, especifícalo
    // protected $table = 'locations';

    // Si quieres desactivar timestamps automáticos
    public $timestamps = false;

    // O si quieres usar timestamps, pero el campo timestamp es personalizado
    // const CREATED_AT = 'timestamp';
    // const UPDATED_AT = null;

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}