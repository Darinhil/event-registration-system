<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    public function checkIn(string $token, User $staff): CheckIn
    {
        $registration = Registration::where('qr_token', $token)->first();
        if (! $registration) { throw ValidationException::withMessages(['qr_token' => 'Registration not found.']); }
        if ($registration->checkIn()->exists()) { throw ValidationException::withMessages(['qr_token' => 'This registration is already checked in.']); }
        return $registration->checkIn()->create(['checked_in_at' => now(), 'checked_in_by' => $staff->id]);
    }
}