<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MindsetAssessmentResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_key',
        'pillar',
        'score',
        'observation',
    ];

    public function assessment()
    {
        return $this->belongsTo(MindsetAssessment::class, 'mindset_assessment_id');
    }
}
