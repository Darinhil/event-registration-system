<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    /** Resolve a QR token to its registration with event + check-in loaded, or fail. */
    public function preview(string $token): Registration
    {
        $registration = Registration::query()
            ->with(['checkIn', 'event.formFields'])
            ->where('qr_token', $token)
            ->first();

        if (! $registration) {
            throw ValidationException::withMessages(['qr_token' => 'Registration not found.']);
        }

        return $registration;
    }

    /**
     * Resolve a desk search: a QR token, an exact registration code, or a
     * unique partial match on code / name / email. Returns the matched
     * registration, or null with suggestions when the query is ambiguous.
     */
    public function findByQuery(Event $event, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw ValidationException::withMessages(['query' => 'Enter a registration code, name, or email.']);
        }

        $base = fn () => $event->registrations()->with(['checkIn', 'event.formFields']);

        // 1) Exact registration code (case-insensitive) — the intended path.
        $registration = (clone $base)()->whereRaw('LOWER(registration_code) = ?', [strtolower($query)])->first();
        if ($registration) {
            return ['registration' => $registration, 'suggestions' => []];
        }

        // 2) Unique partial match across code, name, email.
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
        $matches = (clone $base)()
            ->where(fn ($q) => $q
                ->where('registration_code', 'like', $like)
                ->orWhere('full_name', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['query' => "No registration found for “{$query}”. Check the code or search by name or email."]);
        }

        if ($matches->count() === 1) {
            return ['registration' => $matches->first(), 'suggestions' => []];
        }

        return [
            'registration' => null,
            'suggestions' => $matches->map(fn (Registration $item) => [
                'id' => $item->id,
                'registration_code' => $item->registration_code,
                'full_name' => $item->full_name,
                'email' => $item->email,
                'checked_in_at' => $item->checkIn?->checked_in_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** Resolve an event entrance-QR token (from /check-in?e=...) to its event, or fail. */
    public function eventFromEntranceToken(string $token): Event
    {
        $event = Event::where('check_in_qr_token', $token)->first();
        if (! $event) {
            throw ValidationException::withMessages(['qr_token' => 'This is not a valid event check-in code.']);
        }

        return $event;
    }

    public function checkIn(string $token, User $staff): CheckIn
    {
        $registration = Registration::where('qr_token', $token)->first();
        if (! $registration) { throw ValidationException::withMessages(['qr_token' => 'Registration not found.']); }
        if ($registration->checkIn()->exists()) { throw ValidationException::withMessages(['qr_token' => 'This registration is already checked in.']); }

        // Guard against double scans racing the unique index.
        return $registration->checkIn()->firstOrCreate(
            [],
            ['checked_in_at' => now(), 'checked_in_by' => $staff->id]
        );
    }
}
