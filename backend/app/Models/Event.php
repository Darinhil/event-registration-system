<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * Event lists must never read embedded banners: one base64 image can be ~2 MB, and sorting rows that carry
     * them makes MySQL fail with "Out of sort memory" (error 1038) while holding them all exhausts PHP memory.
     * So lists are loaded without `branding`, then this runs one unsorted query that returns linked banners as
     * they are and only a flag for embedded ones, which become a signed link to the banner route (signed because
     * an <img> can't send a token).
     */
    public static function attachBannerLinks(EloquentCollection $events): EloquentCollection
    {
        if ($events->isEmpty()) return $events;
        $inline = "branding like '%\"data:%'";
        $banners = static::query()->whereKey($events->modelKeys())
            ->select(['id', DB::raw("case when {$inline} then 1 else 0 end as has_inline_banner"), DB::raw("case when {$inline} then null else branding end as branding")])
            ->get()
            ->keyBy('id');

        return $events->each(function (Event $event) use ($banners): void {
            $banner = $banners->get($event->getKey());
            $branding = $banner?->branding;
            if ($banner && (int) $banner->has_inline_banner) {
                $branding = [...($branding ?? []), 'image' => URL::signedRoute('events.banner', ['event' => $event->id, 'v' => $event->updated_at?->timestamp])];
            }
            $event->branding = $branding;
        });
    }

    public function checkInQrToken(): string
    {
        if (! $this->check_in_qr_token) {
            $this->forceFill(['check_in_qr_token' => (string) \Illuminate\Support\Str::uuid()])->save();
        }

        return $this->check_in_qr_token;
    }
}
