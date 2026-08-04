<?php

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Guards the retry_after invariant: a queue connection's retry_after MUST exceed the longest job
 * timeout, or a long-running job (e.g. a big batch import that runs as ONE job) gets re-reserved
 * mid-run, runs on multiple workers at once, and is force-failed with MaxAttemptsExceededException.
 * This regressed once when the queue was moved to Redis (retry_after stayed at the 90s default).
 */
function longestJobTimeout(): int
{
    $max = 0;

    foreach (glob(app_path('Jobs/*.php')) as $file) {
        $class = 'App\\Jobs\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $props = (new ReflectionClass($class))->getDefaultProperties();
        $timeout = (int) ($props['timeout'] ?? 0);
        $max = max($max, $timeout);
    }

    return $max;
}

it('keeps every queue connection retry_after above the longest job timeout', function () {
    $longest = longestJobTimeout();

    expect($longest)->toBeGreaterThan(0, 'expected at least one job to declare a $timeout');

    foreach (['redis', 'database'] as $connection) {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeGreaterThan(
            $longest,
            "queue.connections.{$connection}.retry_after ({$retryAfter}s) must exceed the longest job timeout ({$longest}s)"
        );
    }
});
