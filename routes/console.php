<?php

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
