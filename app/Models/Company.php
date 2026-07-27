<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'industry',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function employees()
    {
        return $this->hasMany(CompanyEmployee::class);
    }

    public function assessments()
    {
        return $this->hasMany(MindsetAssessment::class);
    }
}
