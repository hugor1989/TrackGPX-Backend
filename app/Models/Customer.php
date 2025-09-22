<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;



class Customer extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'openpay_customer_id', 'email', 'phone', 'password', 'address', 'status'];

    protected $hidden = ['password'];

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}