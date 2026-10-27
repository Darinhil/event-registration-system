<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompliancePolicy extends Model
{
    use HasFactory;

    public const TYPES = ['acknowledgement', 'declaration'];

    protected $fillable = ['title', 'category', 'description', 'requirement_type', 'statement', 'document_url', 'validity_months', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'validity_months' => 'integer'];
    }

    public function records(): HasMany { return $this->hasMany(ComplianceRecord::class); }
}
