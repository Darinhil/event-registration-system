<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Event extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'description', 'starts_at', 'ends_at', 'location', 'capacity', 'branding', 'enabled_fields', 'form_config', 'status', 'check_in_qr_token', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'branding' => 'array', 'enabled_fields' => 'array', 'form_config' => 'array'];
    }

    public function registrations(): HasMany { return $this->hasMany(Registration::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->role === 'admin' ? $query : $query->where('created_by', $user->id);
    }
    public function formFields(): HasMany { return $this->hasMany(FormField::class)->orderBy('sort_order'); }

    public function checkInQrToken(): string
    {
        if (! $this->check_in_qr_token) {
            $this->forceFill(['check_in_qr_token' => (string) \Illuminate\Support\Str::uuid()])->save();
        }

        return $this->check_in_qr_token;
    }
}
