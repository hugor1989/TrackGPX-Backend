<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SimCard extends Model
{
     use HasFactory;

    protected $fillable = [
        'sim_id',
        'device_id', 
        'carrier',
        'phone_number',
        'iccid',
        'status',
        'imsi',
        'subscription_type',
        'data_usage',
        'voice_usage', 
        'sms_usage',
        'plan_name',
        'client_name',
        'device_brand',
        'data_limit',
        'monthly_fee',
        'activation_date',
        'expiration_date',
        'notes',
        'apn'
    ];

    

    protected $casts = [
        'data_usage' => 'decimal:2',
        'data_limit' => 'decimal:2', 
        'monthly_fee' => 'decimal:2',
        'activation_date' => 'date',
        'expiration_date' => 'date',
    ];

    public function device()
    {
        return $this->hasOne(Device::class, 'sim_card_id');
    }
}