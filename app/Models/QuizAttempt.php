<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'quiz_id',
        'submission_id',
        'result_access_token_hash',
        'result_access_expires_at',
        'channel',
        'environment',
        'is_internal',
        'started_at',
        'matures_at',
        'submitted_at',
        'submission_mode',
        'is_valid_completion',
        'invalid_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'matures_at' => 'datetime',
            'submitted_at' => 'datetime',
            'result_access_expires_at' => 'datetime',
            'is_internal' => 'boolean',
            'is_valid_completion' => 'boolean',
        ];
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }
}
