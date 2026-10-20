<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CheckInService
{
    public function findRegistration(string $credential, ?User $staff = null): Registration
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

        $allowed = $registration !== null;
        if ($allowed && $staff) {
            if ($staff->isStaff()) {
                $allowed = $staff->isMasterAdmin()
                    || $staff->hasPermission('checkins.manage');
            } else {
                $allowed = (int) $registration->user_id === (int) $staff->id;
            }
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['credential' => 'Registration not found. Check the QR code or attendee code.']);
        }

        return $registration;
    }

    public function checkIn(string $credential, User $staff): CheckIn
    {
        $registration = $this->findRegistration($credential, $staff);

        try {
            return DB::transaction(function () use ($registration, $staff): CheckIn {
                $lockedRegistration = Registration::query()->lockForUpdate()->findOrFail($registration->id);
                if ($lockedRegistration->checkIn()->exists()) {
                    throw ValidationException::withMessages(['credential' => 'This registration is already checked in.']);
                }
                $checkIn = $lockedRegistration->checkIn()->create(['checked_in_at' => now(), 'checked_in_by' => $staff->id]);
                $lockedRegistration->update(['status' => 'checked_in']);

                return $checkIn;
            });
        } catch (QueryException $exception) {
            if (CheckIn::query()->where('registration_id', $registration->id)->exists()) {
                throw ValidationException::withMessages(['credential' => 'This registration is already checked in.']);
            }
            throw $exception;
        }
    }
}
