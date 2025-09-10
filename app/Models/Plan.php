<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['name', 'price', 'duration_days', 'description'];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}