<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;



class Customer extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'openpay_customer_id',
        'email',
        'phone',
        'password',
        'address',
        'role',       // 🔥 CLAVE
        'parent_id',
        'status'
    ];

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

    public function devices()
    {
        return $this->hasMany(Device::class, 'customer_id');
    }

    // app/Models/Customer.php

    public function members()
    {
        return $this->hasMany(Customer::class, 'parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(Customer::class, 'parent_id');
    }

    public function sharedDevices()
    {
        return $this->belongsToMany(
            Device::class,
            'device_customer', // tabla pivote
            'customer_id',
            'device_id'
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMember(): bool
    {
        return $this->role === 'member';
    }
}
