<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Roles that can sign in to the admin workspace. Keep in sync with frontend/src/stores/auth.js. */
    public const STAFF_ROLES = ['admin', 'event_admin', 'manager', 'officer_3', 'officer_2', 'officer_1', 'officer_0'];

    public function isStaff(): bool { return in_array($this->role, self::STAFF_ROLES, true); }

    public function isMasterAdmin(): bool { return $this->role === 'admin'; }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'username',
        'profile_photo',
        'personal_information',
        'professional_qualifications',
        'family_information',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'personal_information' => 'array',
            'professional_qualifications' => 'array',
            'family_information' => 'array',
        ];
    }

    public function registrations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function staffContracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StaffContract::class);
    }

    public function financeVouchers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FinanceVoucher::class);
    }
}
