<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Choice extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'body',
        'is_correct',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
