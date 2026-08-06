<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Live queue status — a compact strip. Polls every 10s so you can watch an import drain the queue
 * without refreshing. "Processing now" = jobs a worker has reserved; "Queued" = jobs still waiting.
 * Reads the real queue: Redis when that's the driver (the DB `jobs` table is unused once the queue
 * moves to Redis), falling back to the `jobs` table for the database driver.
 */
class ImportActivityStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0; // top of the dashboard — a live status strip

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        [$processing, $queued] = $this->queueDepth();
        $failed = (int) DB::table('failed_jobs')->count();

        return [
            Stat::make('Processing now', number_format($processing))
                ->color($processing > 0 ? 'info' : 'gray'),

            Stat::make('Queued', number_format($queued))
                ->color($queued > 0 ? 'warning' : 'gray'),

            Stat::make('Failed', number_format($failed))
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }

    /**
     * [reserved (processing now), pending (queued)] across every queue the workers poll.
     *
     * @return array{0: int, 1: int}
     */
    protected function queueDepth(): array
    {
        if (config('queue.default') === 'redis') {
            try {
                $redis = Redis::connection(config('queue.connections.redis.connection') ?: 'default');
                $reserved = 0;
                $pending = 0;
                foreach (['default', 'chargebacks', 'address-verify'] as $queue) {
                    $pending += (int) $redis->llen("queues:{$queue}");
                    $reserved += (int) $redis->zcard("queues:{$queue}:reserved");
                }

                return [$reserved, $pending];
            } catch (\Throwable) {
                // Redis unavailable — fall through to the database queue table.
            }
        }

        return [
            (int) DB::table('jobs')->whereNotNull('reserved_at')->count(),
            (int) DB::table('jobs')->whereNull('reserved_at')->count(),
        ];
    }
}
