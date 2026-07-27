<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyEmployee extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'email',
        'job_title',
        'department',
        'seniority_months',
    ];

    protected $appends = [
        'full_name',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assessments()
    {
        return $this->hasMany(MindsetAssessment::class);
    }

    public function latestAssessment()
    {
        return $this->hasOne(MindsetAssessment::class)->latestOfMany('assessed_at');
    }
}
