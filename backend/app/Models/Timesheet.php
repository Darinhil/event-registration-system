<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timesheet extends Model
{
    use HasFactory;

    public const STANDARD_DAY_HOURS = 6.5;  // net hours, excluding the 12:00–13:30 unpaid break
    public const HALF_DAY_MAX_HOURS = 3.25;
    public const HOURS_STEP = 0.25;
    public const LEAVE_TYPES = ['annual' => 'Annual', 'sick' => 'Sick', 'special' => 'Special', 'compassionate' => 'Compassionate', 'time_in_lieu' => 'Time in Lieu', 'paternity' => 'Paternity', 'unpaid' => 'Unpaid', 'study' => 'Study'];
    /** Used when HR hasn't recorded an entitlement for the person and year. */
    public const DEFAULT_ENTITLEMENTS = ['annual' => 15];
    /** Who can approve timesheets (the same supervisors who approve overtime). */
    public const APPROVER_ROLES = OvertimeRequest::APPROVER_ROLES;
    public const EDITABLE_STATUSES = ['draft', 'returned'];

    protected $fillable = ['user_id', 'year', 'month', 'status', 'entries', 'approver_id', 'comment', 'submitted_at', 'decided_by', 'decided_at', 'return_comment', 'day_comments', 'processed_at', 'history'];

    protected function casts(): array
    {
        return ['entries' => 'array', 'day_comments' => 'array', 'history' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'processed_at' => 'datetime', 'year' => 'integer', 'month' => 'integer'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_id'); }

    /** Leave days per type in this timesheet: a full day counts 1, a half day 0.5. */
    public function leaveDays(): array
    {
        $days = [];
        foreach ($this->entries ?? [] as $entry) {
            if (! empty($entry['leave_type'])) $days[$entry['leave_type']] = ($days[$entry['leave_type']] ?? 0) + (($entry['leave_part'] ?? 'full') === 'full' ? 1 : 0.5);
        }
        return $days;
    }

    public function addHistory(string $action, User $by, ?string $note = null): void
    {
        $this->history = [...($this->history ?? []), ['action' => $action, 'by' => $by->name, 'at' => now()->toIso8601String(), 'note' => $note]];
    }
}
