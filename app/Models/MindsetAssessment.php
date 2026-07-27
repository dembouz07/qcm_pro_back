<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MindsetAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'company_employee_id',
        'evaluator_id',
        'type',
        'assessed_at',
        'total_score',
        'level',
        'action_items',
        'support_needs',
        'next_review_at',
    ];

    protected function casts(): array
    {
        return [
            'assessed_at' => 'date',
            'next_review_at' => 'date',
            'action_items' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employee()
    {
        return $this->belongsTo(CompanyEmployee::class, 'company_employee_id');
    }

    public function evaluator()
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function responses()
    {
        return $this->hasMany(MindsetAssessmentResponse::class);
    }
}
