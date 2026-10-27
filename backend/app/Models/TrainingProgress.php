<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingProgress extends Model
{
    use HasFactory;

    protected $table = 'training_progress';

    protected $fillable = ['user_id', 'training_course_id', 'completed_lessons', 'started_at', 'best_score', 'attempts', 'completed_at', 'certificate_code'];

    protected function casts(): array
    {
        return ['completed_lessons' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'best_score' => 'integer', 'attempts' => 'integer'];
    }

    public function course(): BelongsTo { return $this->belongsTo(TrainingCourse::class, 'training_course_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
