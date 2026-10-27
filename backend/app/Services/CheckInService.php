<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CheckInService
{
    /**
     * Resolve a QR payload, bare qr_token or registration code to a registration
     * the given user may act on: staff for events they can view, and (when
     * $allowOwner is set) attendees for their own pass. Anything else is reported
     * as "not found" so codes for other people's registrations can't be probed.
     */
    public function findRegistration(string $credential, User $user, bool $allowOwner = false): Registration
    {
        $credential = trim($credential);
        $payload = json_decode($credential, true);
        $token = is_array($payload) ? ($payload['qr_token'] ?? null) : (Str::isUuid($credential) ? $credential : null);
        $registrationCode = is_array($payload) ? ($payload['registration_code'] ?? null) : $credential;

        $registration = ($token || $registrationCode) ? Registration::query()
            ->with(['checkIn', 'event'])
            ->when($token, fn ($query) => $query->where('qr_token', $token))
            ->when(! $token, fn ($query) => $query->where('registration_code', $registrationCode))
            ->first() : null;

        $allowed = $registration && (
            ($allowOwner && $registration->user_id === $user->id)
            || ($user->isStaff() && $registration->event && $user->can('view', $registration->event))
        );
        if (! $allowed) { throw ValidationException::withMessages(['credential' => 'Registration not found. Check the QR code or attendee code.']); }

        return $registration;
    }

    public function checkIn(string $credential, User $user): CheckIn
    {
        $registration = $this->findRegistration($credential, $user, allowOwner: true);
        if ($registration->event?->status === 'cancelled') { throw ValidationException::withMessages(['credential' => 'This event was cancelled, so check-in is closed.']); }
        if ($registration->checkIn()->exists()) { throw ValidationException::withMessages(['credential' => 'This registration is already checked in.']); }
        $checkIn = $registration->checkIn()->create(['checked_in_at' => now(), 'checked_in_by' => $user->id]);
        $registration->update(['status' => 'checked_in']);

        return $checkIn;
    }
}
