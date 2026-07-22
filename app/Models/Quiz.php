<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'type',
        'stage_threshold',
        'require_stage_pass',
        'school_class_id',
        'created_by',
        'starts_at',
        'ends_at',
        'closed_at',
        'is_published',
        'show_corrections',
        'archived_at',
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_published' => 'boolean',
            'show_corrections' => 'boolean',
            'stage_threshold' => 'integer',
            'require_stage_pass' => 'boolean',
        ];
    }

    public function isProgressive(): bool
    {
        return $this->type === 'progressive';
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order_index');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function isLocked(): bool
    {
        if ($this->isProgressive() || $this->starts_at === null) {
            return false;
        }

        return Carbon::now()->lt($this->starts_at);
    }

    public function isClosed(int $gracePeriodSeconds = 0): bool
    {
        if (!$this->is_published || $this->archived_at !== null || $this->closed_at !== null) {
            return true;
        }

        if ($this->isProgressive() || $this->ends_at === null) {
            return false;
        }
        
        $closedAt = $this->ends_at->copy()->addSeconds($gracePeriodSeconds);
        return Carbon::now()->gt($closedAt);
    }

    public function isOpen(): bool
    {
        return $this->is_published && !$this->isLocked() && !$this->isClosed();
    }
}
