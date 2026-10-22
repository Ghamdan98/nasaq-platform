<?php

namespace App\Providers;

use App\Enums\UserRole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(\App\Models\SystemSetting::class, \App\Policies\SystemSettingPolicy::class);
        $permissions = [
            'view_transactions',
            'create_transactions',
            'edit_transactions',
            'adjust_transaction_values',
            'submit_transactions',
            'review_transactions',
            'approve_transactions',
            'approve_legal_review_transaction',
            'reject_transactions',
            'request_transaction_changes',
            'cancel_transactions',
            'reopen_approved_transaction',
            'export_transactions',
        ];

        foreach ($permissions as $permission) {
            Gate::define($permission, function ($user) use ($permission): bool {
                if ($permission === 'adjust_transaction_values') {
                    return $user->canAdjustTransactionValues();
                }

                return $user->hasPermission($permission);
            });
        }
    }
}
