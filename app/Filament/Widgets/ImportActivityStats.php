<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Live queue + import status. Polls every 10s so you can watch an import drain the queue without
 * refreshing. "Processing now" = jobs the workers have reserved; "Queued" = jobs still waiting.
 */
class ImportActivityStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0; // top of the dashboard — a live status strip

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $inFlight = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $queued = DB::table('jobs')->whereNull('reserved_at')->count();
        $failed = DB::table('failed_jobs')->count();
        $lastImport = DB::table('carrier_import_files')->max('imported_at');

        return [
            Stat::make('Processing now', number_format($inFlight))
                ->description($inFlight > 0 ? 'import running' : 'idle')
                ->descriptionIcon($inFlight > 0 ? 'heroicon-m-bolt' : 'heroicon-m-check-circle')
                ->color($inFlight > 0 ? 'info' : 'gray'),

            Stat::make('Queued', number_format($queued))
                ->description($queued > 0 ? 'waiting for a worker' : 'nothing waiting')
                ->color($queued > 0 ? 'warning' : 'gray'),

            Stat::make('Failed', number_format($failed))
                ->description('see Failed Jobs to retry/purge')
                ->color($failed > 0 ? 'danger' : 'gray'),

            Stat::make('Last import', $lastImport ? Carbon::parse($lastImport)->diffForHumans() : '—')
                ->description($lastImport ? Carbon::parse($lastImport)->format('M j, g:i a') : 'no imports yet')
                ->color('gray'),
        ];
    }
}
