<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CheckInService
{
    public function findRegistration(string $credential): Registration
    {
        $credential = trim($credential);
        $payload = json_decode($credential, true);
        $token = is_array($payload) ? ($payload['qr_token'] ?? null) : (Str::isUuid($credential) ? $credential : null);
        $registrationCode = is_array($payload) ? ($payload['registration_code'] ?? null) : $credential;

        $registration = Registration::query()
            ->with(['checkIn', 'event'])
            ->when($token, fn ($query) => $query->where('qr_token', $token))
            ->when(! $token, fn ($query) => $query->where('registration_code', $registrationCode))
            ->first();

        if (! $registration) { throw ValidationException::withMessages(['credential' => 'Registration not found. Check the QR code or attendee code.']); }

        return $registration;
    }

    public function checkIn(string $credential, User $staff): CheckIn
    {
        $registration = $this->findRegistration($credential);
        if ($registration->checkIn()->exists()) { throw ValidationException::withMessages(['credential' => 'This registration is already checked in.']); }
        $checkIn = $registration->checkIn()->create(['checked_in_at' => now(), 'checked_in_by' => $staff->id]);
        $registration->update(['status' => 'checked_in']);

        return $checkIn;
    }
}
