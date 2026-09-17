<?php

namespace App\Services;

use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Str;

class RegistrationService
{
    public function create(User $user, array $data): Registration
    {
        return $user->registrations()->create([...$data, 'qr_token' => (string) Str::uuid()]);
    }
}