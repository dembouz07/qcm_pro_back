<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'body',
        'points',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'order_index' => 'integer',
        ];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function choices()
    {
        return $this->hasMany(Choice::class)->orderBy('order_index');
    }
}
