<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = ['device_id', 'event_type', 'description', 'event_time'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}