<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceRecord extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_policy_id', 'user_id', 'completed_at', 'expires_at', 'response', 'signature'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime', 'response' => 'array'];
    }

    public function policy(): BelongsTo { return $this->belongsTo(CompliancePolicy::class, 'compliance_policy_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isExpired(): bool { return $this->expires_at !== null && $this->expires_at->isPast(); }
}
