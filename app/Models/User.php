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
        'is_blocked',
        'school_class_id',
        'subscription_plan',
        'subscription_status',
        'subscribed_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'is_super_admin',
        'current_plan',
        'plan_features',
        'is_subscription_active',
    ];

    public const PLAN_FREE = 'free';
    public const PLAN_ESSENTIAL = 'essential';
    public const PLAN_PREMIUM = 'premium';

    public const PLAN_FEATURES = [
        self::PLAN_FREE => [
            'quiz_manual',
            'quiz_import',
            'quiz_progressive',
        ],
        self::PLAN_ESSENTIAL => [
            'quiz_manual',
            'quiz_import',
            'quiz_progressive',
            'quiz_smart',
        ],
        self::PLAN_PREMIUM => [
            'quiz_manual',
            'quiz_import',
            'quiz_progressive',
            'quiz_smart',
            'surveys',
            'wrong_question_stats',
        ],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'subscribed_until' => 'datetime',
            'is_blocked' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        $supers = array_map('strtolower', (array) config('app.super_admins', []));
        return in_array(strtolower((string) $this->email), $supers, true);
    }

    /**
     * Super-administrateur de plateforme (rôle dédié).
     */
    public function isPlatformAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->isSuperAdmin();
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->isSuperAdmin() || $this->isPlatformAdmin()) {
            return true;
        }

        // La formule gratuite n'expire pas. Une formule payante expirée
        // revient automatiquement aux droits de la formule gratuite.
        return $this->effectiveSubscriptionPlan() === self::PLAN_FREE
            || $this->isPaidSubscriptionActive();
    }

    public function isPaidSubscriptionActive(): bool
    {
        return in_array($this->subscription_plan, [self::PLAN_ESSENTIAL, self::PLAN_PREMIUM], true)
            && $this->subscription_status === 'active'
            && $this->subscribed_until !== null
            && $this->subscribed_until->isFuture();
    }

    public function effectiveSubscriptionPlan(): string
    {
        if ($this->isSuperAdmin() || $this->isPlatformAdmin()) {
            return self::PLAN_PREMIUM;
        }

        $storedPlan = array_key_exists((string) $this->subscription_plan, self::PLAN_FEATURES)
            ? (string) $this->subscription_plan
            : self::PLAN_FREE;

        if ($storedPlan === self::PLAN_FREE || !$this->isPaidSubscriptionActive()) {
            return self::PLAN_FREE;
        }

        return $storedPlan;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, self::PLAN_FEATURES[$this->effectiveSubscriptionPlan()] ?? [], true);
    }

    public function getCurrentPlanAttribute(): string
    {
        return $this->effectiveSubscriptionPlan();
    }

    public function getPlanFeaturesAttribute(): array
    {
        return self::PLAN_FEATURES[$this->effectiveSubscriptionPlan()] ?? [];
    }

    public function getIsSubscriptionActiveAttribute(): bool
    {
        return $this->hasActiveSubscription();
    }

    public static function subscriptionPlans(): array
    {
        return [
            self::PLAN_FREE => [
                'id' => self::PLAN_FREE,
                'name' => 'Gratuite',
                'price' => 0,
                'features' => self::PLAN_FEATURES[self::PLAN_FREE],
            ],
            self::PLAN_ESSENTIAL => [
                'id' => self::PLAN_ESSENTIAL,
                'name' => 'Essentielle',
                'price' => 3000,
                'features' => self::PLAN_FEATURES[self::PLAN_ESSENTIAL],
            ],
            self::PLAN_PREMIUM => [
                'id' => self::PLAN_PREMIUM,
                'name' => 'Complète',
                'price' => 5000,
                'features' => self::PLAN_FEATURES[self::PLAN_PREMIUM],
            ],
        ];
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
