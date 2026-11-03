<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffRequest extends Model
{
    public const TYPES = ['leave' => 'Leave', 'travel' => 'Travel', 'fuel' => 'Fuel log-sheet', 'purchase' => 'Purchase', 'profile_change' => 'Profile change'];

    protected $fillable = ['user_id', 'type', 'approver_id', 'status', 'details', 'attachment_path', 'attachment_name', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['details' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    /** Goes to the chosen supervisor; a Master Admin (HR) can decide any request. */
    public function canBeDecidedBy(User $user): bool
    {
        return $this->status === 'pending' && $this->user_id !== $user->id && ($this->approver_id === $user->id || $user->isMasterAdmin());
    }
}
