<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCourse extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'category', 'summary', 'estimated_minutes', 'pass_mark', 'due_date', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'is_active' => 'boolean', 'pass_mark' => 'integer', 'estimated_minutes' => 'integer'];
    }

    public function lessons(): HasMany { return $this->hasMany(TrainingLesson::class)->orderBy('sort_order')->orderBy('id'); }
    public function questions(): HasMany { return $this->hasMany(TrainingQuestion::class)->orderBy('sort_order')->orderBy('id'); }
}
