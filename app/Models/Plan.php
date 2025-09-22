<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'openpay_plan_id',
        'name',
        'description',
        'features',
        'price',
        'currency',
        'interval',
        'interval_count',
        'status'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'interval_count' => 'integer',
        'features' => 'array',
    ];


    /**
     * Relación con suscripciones
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Scope para planes activos
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Convertir duration_days a formato OpenPay
     */
    public function getOpenPayInterval()
    {
        // Si originalmente usabas duration_days, podemos convertirlo
        // Por ejemplo: 30 days = 1 month, 7 days = 1 week, etc.
        if ($this->interval_count >= 365) {
            return ['interval' => 'year', 'interval_count' => floor($this->interval_count / 365)];
        } elseif ($this->interval_count >= 30) {
            return ['interval' => 'month', 'interval_count' => floor($this->interval_count / 30)];
        } elseif ($this->interval_count >= 7) {
            return ['interval' => 'week', 'interval_count' => floor($this->interval_count / 7)];
        } else {
            return ['interval' => 'day', 'interval_count' => $this->interval_count];
        }
    }
}