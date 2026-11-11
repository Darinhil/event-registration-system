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

    /** Staff role titles are checked by the operational authorization helpers below. */
    public const STAFF_ROLE_TITLES = ['system_administrator', 'operations_lead', 'systems_engineer', 'maintenance_tech', 'reviewer'];
    public const STAFF_TEAMS = ['Management', 'Operation', 'CREFA', 'ESCM', 'AWR', 'CLIMART', 'GROWTH-2', 'PV-SKILL', 'FNC', 'CHILL', 'SIDA CSO'];
    public const STAFF_POSITIONS = ['Executive Director', 'Senior Manager', 'Manager', 'Coordinator', 'Officer', 'Assistant', 'Volunteer', 'Intern', 'Others'];
    public const STAFF_AUTHORITIES = ['approver', 'verifier', 'reviewer', 'checker', 'maker'];

    public function isStaff(): bool { return in_array($this->role, self::STAFF_ROLES, true); }

    public function isMasterAdmin(): bool { return $this->role === 'admin' || $this->hasStaffRole('system_administrator'); }

    public function hasStaffRole(string $role): bool { return $this->staff_role_title === $role; }

    public function hasAuthority(string $authority): bool
    {
        return $this->isMasterAdmin() || in_array($authority, $this->staff_authorities ?? [], true);
    }

    public function canViewAllEvents(): bool
    {
        return $this->isMasterAdmin() || in_array($this->staff_role_title, [
            'operations_lead', 'systems_engineer', 'maintenance_tech', 'reviewer',
        ], true);
    }

    public function canManageEvents(): bool
    {
        if ($this->isMasterAdmin()) return true;
        if ($this->staff_role_title !== null) {
            return in_array($this->staff_role_title, ['operations_lead', 'systems_engineer'], true);
        }

        return $this->role === 'event_admin';
    }

    public function canCreateEvents(): bool
    {
        return ! $this->hasStaffRole('reviewer') && ($this->canManageEvents() || $this->hasAuthority('maker'));
    }

    public function canPerformCheckIn(): bool
    {
        if ($this->isMasterAdmin()) return true;
        if ($this->hasStaffRole('reviewer')) return false;
        if ($this->staff_role_title !== null) {
            return in_array($this->staff_role_title, ['operations_lead', 'systems_engineer', 'maintenance_tech'], true)
                || $this->hasAuthority('checker')
                || $this->hasAuthority('maker');
        }

        return $this->isStaff();
    }

    public function canApproveWorkflows(): bool
    {
        if ($this->isMasterAdmin()) return true;
        if ($this->hasStaffRole('reviewer')) return false;
        if ($this->staff_role_title !== null) {
            return $this->hasStaffRole('operations_lead') || $this->hasAuthority('approver');
        }

        return in_array($this->role, ['admin', 'manager'], true);
    }

    public function canVerifyWorkflows(): bool
    {
        return $this->isMasterAdmin()
            || (! $this->hasStaffRole('reviewer') && (
                $this->hasStaffRole('operations_lead')
                || $this->hasAuthority('verifier')
            ));
    }

    public function canViewParticipants(): bool
    {
        if ($this->isMasterAdmin()) return true;
        if ($this->staff_role_title !== null) {
            return in_array($this->staff_role_title, ['operations_lead', 'systems_engineer', 'maintenance_tech'], true);
        }

        return $this->isStaff();
    }

    public function canManageFinance(): bool
    {
        return $this->isMasterAdmin()
            || $this->hasStaffRole('operations_lead')
            || ($this->staff_role_title === null && $this->role === 'event_admin');
    }

    public function canCreateFinance(): bool
    {
        return ! $this->hasStaffRole('reviewer') && ($this->canManageFinance() || $this->hasAuthority('maker'));
    }

    public function canViewFinance(): bool
    {
        return $this->canManageFinance()
            || $this->hasStaffRole('reviewer')
            || $this->hasAuthority('reviewer');
    }

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
        'personnel_profile',
        'personnel_profile_submitted_at',
        'password',
        'role',
        'staff_id',
        'first_name',
        'last_name',
        'staff_team',
        'staff_position',
        'staff_role_title',
        'staff_authorities',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'personnel_profile',
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
            'personnel_profile' => 'array',
            'staff_authorities' => 'array',
            'personnel_profile_submitted_at' => 'datetime',
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
