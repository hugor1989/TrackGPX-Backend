<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FcmToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'token',
        'device_name'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
