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
        Gate::define('manage-events', fn (User $user): bool => in_array($user->role, ['admin', 'event_admin', 'manager', 'officer_3', 'officer_2', 'officer_1', 'officer_0'], true));
        Gate::define('manage-admin-accounts', fn (User $user): bool => $user->role === 'admin');
    }
}
