<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    use HasFactory;

    /** Roles that can be chosen as the approving supervisor. */
    public const APPROVER_ROLES = ['admin', 'manager'];

    /** Statuses that still count towards a person's overtime (used for overlap checks). */
    public const ACTIVE_STATUSES = ['pending', 'approved'];

    protected $fillable = ['user_id', 'supervisor_id', 'work_date', 'start_at', 'end_at', 'minutes', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'start_at' => 'datetime', 'end_at' => 'datetime', 'reviewed_at' => 'datetime', 'minutes' => 'integer'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function supervisor(): BelongsTo { return $this->belongsTo(User::class, 'supervisor_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
