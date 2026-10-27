<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['training_course_id', 'prompt', 'options', 'correct_option', 'sort_order'];

    // The answer key stays on the server.
    protected $hidden = ['correct_option'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct_option' => 'integer'];
    }

    public function course(): BelongsTo { return $this->belongsTo(TrainingCourse::class, 'training_course_id'); }
}
