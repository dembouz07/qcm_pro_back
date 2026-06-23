<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quiz_id',
        'participant_nom',
        'participant_prenom',
        'participant_referentiel',
        'score',
        'total_points',
        'percentage',
        'note_sur_20',
        'stade_atteint',
        'stage_scores',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'total_points' => 'float',
            'percentage' => 'float',
            'note_sur_20' => 'float',
            'stade_atteint' => 'integer',
            'stage_scores' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function answers()
    {
        return $this->hasMany(SubmissionAnswer::class);
    }
}
