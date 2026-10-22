<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCourse extends Model
{
    protected $fillable = [
        'title',
        'category',
        'description',
        'content',
        'quiz',
        'due_date',
        'pass_percent',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quiz' => 'array',
            'due_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TrainingCourseAssignment::class);
    }
}
