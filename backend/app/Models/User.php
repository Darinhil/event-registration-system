<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
        'password',
        'role',
        'account_status',
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! $user->role) {
                return;
            }
            $roleKey = [
                'master_admin' => 'admin', 'event_admin' => 'maker', 'manager' => 'approver',
                'officer_3' => 'checker', 'officer_2' => 'reviewer', 'officer_1' => 'verifier', 'officer_0' => 'maker',
            ][$user->role] ?? $user->role;
            $user->role_id = Role::query()->where('key', $roleKey)->value('id');
        });
    }

    public function registrations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function isMasterAdmin(): bool
    {
        return in_array($this->role, ['admin', 'master_admin'], true);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'master_admin', 'maker', 'checker', 'reviewer', 'verifier', 'approver', 'event_admin', 'manager', 'officer_3', 'officer_2', 'officer_1', 'officer_0'], true);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->hasAnyPermission([$permission]);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isMasterAdmin()) {
            return true;
        }

        $roleKey = [
            'event_admin' => 'maker',
            'manager' => 'approver',
            'officer_3' => 'checker',
            'officer_2' => 'reviewer',
            'officer_1' => 'verifier',
            'officer_0' => 'maker',
        ][$this->role] ?? $this->role;

        if ($this->role_id) {
            return $this->roleDefinition()->whereHas('permissions', fn ($query) => $query->whereIn('permissions.key', $permissions))->exists();
        }

        return Role::query()->where('key', $roleKey)->whereHas('permissions', fn ($query) => $query->whereIn('permissions.key', $permissions))->exists();
    }

    public function roleDefinition(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permissionKeys(): array
    {
        if ($this->isMasterAdmin()) {
            return Permission::query()->orderBy('key')->pluck('key')->all();
        }

        if ($this->role_id) {
            return $this->roleDefinition()->with('permissions:id,key')->first()?->permissions->pluck('key')->all() ?? [];
        }

        $roleKey = [
            'event_admin' => 'maker', 'manager' => 'approver', 'officer_3' => 'checker',
            'officer_2' => 'reviewer', 'officer_1' => 'verifier', 'officer_0' => 'maker',
        ][$this->role] ?? $this->role;

        return Role::query()->where('key', $roleKey)->with('permissions:id,key')->first()?->permissions->pluck('key')->all() ?? [];
    }

    public function staffRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Staff::class);
    }
}
