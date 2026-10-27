<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingLesson extends Model
{
    use HasFactory;

    protected $fillable = ['training_course_id', 'title', 'body', 'sort_order'];

    public function course(): BelongsTo { return $this->belongsTo(TrainingCourse::class, 'training_course_id'); }
}
