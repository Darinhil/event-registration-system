<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffContract extends Model
{
    use HasFactory;

    public const TYPES = ['probationary' => 'Probationary', 'full_time' => 'Full-time', 'part_time' => 'Part-time', 'fixed_term' => 'Fixed-term', 'consultancy' => 'Consultancy', 'internship' => 'Internship'];
    public const CURRENCIES = ['USD', 'KHR'];

    protected $fillable = ['user_id', 'contract_type', 'position', 'department', 'start_date', 'end_date', 'salary', 'currency', 'file_path', 'file_name', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'salary' => 'decimal:2'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** upcoming | active | ended, from today's date. */
    public function status(): string
    {
        $today = now()->startOfDay();
        if ($this->start_date->gt($today)) return 'upcoming';
        if ($this->end_date && $this->end_date->lt($today)) return 'ended';
        return 'active';
    }
}
