<?php

namespace App\Providers;

use App\Filament\Support\GridCsv;
use App\Listeners\SpawnWorkerOnJobQueued;
use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->registerEventListeners();
        $this->registerRateLimiters();

        // Add a small CSV Import/Export icon dropdown to every Filament grid's toolbar, inline
        // with the Filters / column-manager triggers. This global push survives for tables that
        // DON'T set their own toolbarActions (most read-only grids, relation managers). Tables
        // that DO set toolbarActions reset the array — those inject GridCsv::menu() directly in
        // their table class instead. Eloquent-only (GridCsv guards non-query tables).
        Table::configureUsing(function (Table $table): void {
            $table->pushToolbarActions([
                GridCsv::menu(),
            ]);
        });
    }

    /**
     * Throttle the JobCost chargeback push to Pace so a big invoice's fan-out can't hammer the ERP.
     * The ceiling is the Pace connection's editable "Rate Limit (per min)" (blank = unlimited).
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('pace-chargebacks', function () {
            $rpm = (int) Cache::remember('pace.rate_limit_per_minute', now()->addMinutes(5), fn () => (int) IntegrationConnection::byDriver(IntegrationConnection::DRIVER_PACE)->active()->value('rate_limit_per_minute'));

            return $rpm > 0 ? Limit::perMinute($rpm) : Limit::none();
        });
    }

    /**
     * Register event listeners.
     */
    protected function registerEventListeners(): void
    {
        // Auto-spawn workers when jobs are queued
        if (config('queue.auto_spawn_workers', true)) {
            Event::listen(JobQueued::class, SpawnWorkerOnJobQueued::class);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
