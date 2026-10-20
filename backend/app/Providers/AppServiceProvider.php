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
        Gate::define('manage-events', fn (User $user): bool => $user->hasAnyPermission(['users.create', 'users.manage', 'roles.manage', 'workflows.manage', 'events.view', 'events.manage', 'forms.create', 'forms.review', 'forms.verify', 'forms.approve', 'reports.view', 'checkins.manage']));
        Gate::define('view-events', fn (User $user): bool => $user->hasAnyPermission(['events.view', 'checkins.manage']));
        Gate::define('manage-admin-accounts', fn (User $user): bool => $user->isMasterAdmin());
        Gate::define('manage-users', fn (User $user): bool => $user->hasAnyPermission(['users.create', 'users.manage']));
        Gate::define('create-users', fn (User $user): bool => $user->hasPermission('users.create'));
        Gate::define('manage-access-control', fn (User $user): bool => $user->hasAnyPermission(['users.manage', 'roles.manage', 'workflows.manage']));
        foreach (['users.create', 'users.manage', 'roles.manage', 'workflows.manage', 'events.view', 'events.manage', 'forms.create', 'forms.review', 'forms.verify', 'forms.approve', 'reports.view', 'checkins.manage'] as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
