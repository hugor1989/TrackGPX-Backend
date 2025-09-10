<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['vehicle_id', 'imei', 'protocol', 'status', 'last_connection'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function simCard()
    {
        return $this->hasOne(SimCard::class);
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
}