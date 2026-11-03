<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

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

    /** Registration dates are calendar days at the organisation, whatever time zone the server runs in. */
    public function registrationOpensAt(): ?Carbon
    {
        $start = $this->form_config['settings']['registration_start'] ?? null;

        return $start ? Carbon::parse($start, config('app.business_timezone')) : null;
    }

    public function registrationClosesAt(): ?Carbon
    {
        $end = $this->form_config['settings']['registration_end'] ?? null;

        return $end ? Carbon::parse($end, config('app.business_timezone'))->endOfDay() : null;
    }

    /** The form builder can set a limit below the event's capacity. */
    public function registrationLimit(): int
    {
        return (int) ($this->form_config['settings']['max_participants'] ?? $this->capacity);
    }

    /** A link for lists, so they don't carry every embedded image; signed because an <img> can't send a token. */
    public function withBannerLink(): self
    {
        $branding = $this->branding;
        if (str_starts_with((string) ($branding['image'] ?? ''), 'data:')) {
            $branding['image'] = URL::signedRoute('events.banner', ['event' => $this->id, 'v' => $this->updated_at?->timestamp]);
            $this->branding = $branding;
        }

        return $this;
    }

    public function checkInQrToken(): string
    {
        if (! $this->check_in_qr_token) {
            $this->forceFill(['check_in_qr_token' => (string) \Illuminate\Support\Str::uuid()])->save();
        }

        return $this->check_in_qr_token;
    }
}
