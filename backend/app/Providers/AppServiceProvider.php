<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-events', fn (User $user): bool => $user->isStaff());
        Gate::define('manage-operational-events', fn (User $user): bool => $user->canManageEvents());
        Gate::define('create-operational-events', fn (User $user): bool => $user->canCreateEvents());
        Gate::define('use-personnel-portal', fn (User $user): bool => $user->isStaff());
        Gate::define('manage-admin-accounts', fn (User $user): bool => $user->isMasterAdmin());
        Gate::define('view-participants', fn (User $user): bool => $user->canViewParticipants());
        Gate::define('perform-check-in', fn (User $user): bool => $user->canPerformCheckIn());
        Gate::define('view-finance', fn (User $user): bool => $user->canViewFinance());
        Gate::define('manage-finance', fn (User $user): bool => $user->canManageFinance());
        Gate::define('create-finance', fn (User $user): bool => $user->canCreateFinance());
        Gate::define('process-timesheets', fn (User $user): bool => $user->canVerifyWorkflows());
    }
}
