<?php

namespace App\Providers;

use App\Enums\PaymentDirection;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        JsonResource::withoutWrapping();

        $this->defineGates();

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('ticket-email', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Role permissions (plan section 3). Every API route checks these on the server;
     * the React app only uses them to hide controls.
     *
     * owner    – everything
     * staff    – create/update customers, bookings (incl. supplier cost), documents, issues;
     *            record customer receipts only; no company-wide reports
     * readonly – search and view; passport numbers masked; no costs, margins or supplier payments
     */
    private function defineGates(): void
    {
        Gate::define('edit-records', fn (User $user) => $user->canEdit());
        Gate::define('view-passport', fn (User $user) => $user->canEdit());
        Gate::define('view-costs', fn (User $user) => $user->canEdit());
        Gate::define('view-reports', fn (User $user) => $user->isOwner());
        Gate::define('record-payment', fn (User $user, PaymentDirection $direction) => $user->isOwner()
            || ($user->canEdit() && $direction === PaymentDirection::CustomerReceipt));
        Gate::define('manage-payments', fn (User $user) => $user->isOwner());
        Gate::define('delete-documents', fn (User $user) => $user->isOwner());
        Gate::define('manage-expenses', fn (User $user) => $user->isOwner());
        Gate::define('manage-users', fn (User $user) => $user->isOwner());
        Gate::define('manage-settings', fn (User $user) => $user->isOwner());
        Gate::define('view-audit', fn (User $user) => $user->canEdit());
        // Sending ticket PDFs to a customer: owner and booking staff.
        Gate::define('email-tickets', fn (User $user) => $user->canEdit());
        // Confirming an alternative ticket PDF was reviewed and may be sent to the customer.
        Gate::define('verify-documents', fn (User $user) => $user->canEdit());
        // Airlines & logos: staff add and edit; only the owner deletes (never when in use).
        Gate::define('manage-airlines', fn (User $user) => $user->canEdit());
        Gate::define('delete-airlines', fn (User $user) => $user->isOwner());
    }
}
