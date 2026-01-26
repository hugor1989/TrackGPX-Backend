<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    protected $fillable = [
        'user_id', 
        'name', 
        'phone', 
        'email', 
        'relationship',
        'notify_sms',
        'notify_whatsapp',
        'notify_email'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
