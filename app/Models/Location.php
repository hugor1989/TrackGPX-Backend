<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['device_id', 'latitude', 'longitude', 'speed', 'altitude', 'timestamp'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}