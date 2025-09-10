<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLog extends Model
{
    protected $fillable = ['device_id', 'raw_data'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}