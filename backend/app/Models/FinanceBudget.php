<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceBudget extends Model
{
    protected $fillable = ['user_id', 'budget_number', 'department', 'project_name', 'fiscal_year', 'workflow_status', 'payload'];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
