<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Survey extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'questions', 'access_token', 'is_open',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'is_open' => 'boolean',
        ];
    }

    public function responses()
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
