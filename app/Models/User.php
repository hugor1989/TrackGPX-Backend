<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{

    use HasApiTokens, Notifiable;
    /** @use HasFactory<\Database\Factories\UserFactory> */
   protected $fillable = ['name', 'email', 'password', 'active'];

    protected $hidden = ['password'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
