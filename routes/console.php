<?php

use App\Models\CompanySetting;
use App\Models\SystemLog;
use App\Services\WorkerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clean up stale/completed workers every 5 minutes
Schedule::call(function () {
    app(WorkerService::class)->cleanupCompletedWorkers();
})->everyFiveMinutes()->name('worker-cleanup')->withoutOverlapping();

// Run the workers:manage cleanup command every 30 minutes
Schedule::command('workers:manage cleanup --stale-minutes=60')
    ->everyThirtyMinutes()
    ->name('worker-stale-cleanup')
    ->withoutOverlapping();

// Process carrier invoices daily at 12:30 AM
Schedule::command('invoices:process')
    ->dailyAt('00:30')
    ->name('carrier-invoice-processing')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/carrier-invoices.log'));

// Poll carrier invoice mailboxes; each integration is gated by its own
// Check Frequency (poll_minutes), so this 15-minute tick is just the granularity.
Schedule::command('mail:process-invoices --due')
    ->everyFifteenMinutes()
    ->name('mail-invoice-polling')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/mail-invoices.log'));

// Purge Pace address-correction audit logs older than the configured retention
// (Company Setup → Retention days). 0 = keep forever.
Schedule::call(function () {
    $days = (int) (CompanySetting::instance()->pace_correction_retention_days ?? 0);

    if ($days > 0) {
        SystemLog::query()
            ->where('type', 'pace_address_correction')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
})->dailyAt('01:00')->name('pace-correction-purge')->withoutOverlapping();

// Rebuild the carrier reporting rollups after the nightly invoice import (the only
// thing that changes the numbers), so every report + filter stays instant. Imports
// and deletions also trigger a rebuild via the CarrierInvoice observer.
Schedule::command('reports:rebuild')
    ->dailyAt('01:15')
    ->name('carrier-rollups-rebuild')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/report-rollups.log'));

// Pace carton ship costs are read at IMPORT time (SyncInvoiceCartonCosts, dispatched from the
// invoice import), so no nightly pull is needed. `recoup:sync-cartons` remains for manual
// backfill of invoices imported before this was wired.

// Resolve stuck chargeback pushes (create timed out → outcome unknown) by verifying the [CB:] token
// in Pace before ever re-posting. Cheap no-op when nothing is stuck; the anti-double-bill safety net.
Schedule::command('chargebacks:reconcile')
    ->everyFiveMinutes()
    ->name('chargebacks-reconcile')
    ->withoutOverlapping();

// Prune Telescope debug entries nightly (keep 48h). telescope_entries is otherwise the largest
// table and grows unbounded; Telescope stays on for now but doesn't need weeks of history.
Schedule::command('telescope:prune --hours=48')
    ->dailyAt('02:00')
    ->name('telescope-prune')
    ->withoutOverlapping();

// Auto-purge failed queue jobs older than 7 days so they don't accumulate. Recent ones stay
// visible in the Failed Jobs admin page (Admin → Failed Jobs) for review/retry.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:10')
    ->name('failed-jobs-prune')
    ->withoutOverlapping();
