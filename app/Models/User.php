<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'school_class_id',
        'subscription_status',
        'subscribed_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['is_super_admin'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscribed_until' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        $supers = array_map('strtolower', (array) config('app.super_admins', []));
        return in_array(strtolower((string) $this->email), $supers, true);
    }

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->isSuperAdmin();
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->subscription_status === 'active'
            && $this->subscribed_until !== null
            && $this->subscribed_until->isFuture();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }
}
