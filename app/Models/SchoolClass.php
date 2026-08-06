<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'academic_year', 'code', 'owner_id'];

    protected static function booted(): void
    {
        static::creating(function (SchoolClass $schoolClass): void {
            $schoolClass->academic_year ??= self::currentAcademicYear();
        });
    }

    public static function currentAcademicYear(): string
    {
        $today = now();
        $startYear = $today->month >= 8 ? $today->year : $today->year - 1;

        return sprintf('%d-%d', $startYear, $startYear + 1);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }
}
