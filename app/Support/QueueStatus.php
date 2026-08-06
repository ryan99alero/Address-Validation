<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Live queue depth for the dashboard status strip. Reads the real queue: Redis when that's the
 * driver (the DB `jobs` table is unused once the queue moves to Redis), falling back to the `jobs`
 * table for the database driver / test environments.
 */
class QueueStatus
{
    /**
     * @return array{processing: int, queued: int, failed: int}
     */
    public static function counts(): array
    {
        [$processing, $queued] = self::depth();

        return [
            'processing' => $processing,
            'queued' => $queued,
            'failed' => (int) DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * [reserved (processing now), pending (queued)] across every queue the workers poll.
     *
     * @return array{0: int, 1: int}
     */
    protected static function depth(): array
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
