<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimCard extends Model
{
    protected $fillable = ['device_id', 'carrier', 'phone_number', 'iccid', 'status'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}