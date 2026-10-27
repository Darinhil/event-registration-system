<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingQuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'training_course_id', 'answers', 'score', 'passed'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'score' => 'integer', 'passed' => 'boolean'];
    }
}
