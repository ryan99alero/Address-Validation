<?php

use App\Filament\Pages\FailedJobs;
use Illuminate\Support\Facades\DB;

function insertFailedJob(string $uuid, string $displayName, string $exception): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName]),
        'exception' => $exception,
        'failed_at' => now(),
    ]);
}

test('rows maps failed_jobs into display rows keyed by id', function () {
    insertFailedJob('abc-123', 'App\\Jobs\\ProcessFolderChunk', "RuntimeException: boom\n#0 /app/foo.php(1)");

    $rows = FailedJobs::rows();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();
    expect($row['name'])->toBe('App\\Jobs\\ProcessFolderChunk')
        ->and($row['queue'])->toBe('default')
        ->and($row['uuid'])->toBe('abc-123')
        ->and($row['error'])->toBe('RuntimeException: boom')
        ->and($rows->keys()->first())->toBe($row['id']);
});

test('rows falls back to a label when the payload is unparseable', function () {
    insertFailedJob('def-456', 'x', 'Some error');
    DB::table('failed_jobs')->where('uuid', 'def-456')->update(['payload' => 'not-json']);

    expect(FailedJobs::rows()->firstWhere('uuid', 'def-456')['name'])->toBe('Unknown job');
});

test('the failed jobs page is admin-gated', function () {
    expect(FailedJobs::canAccess())->toBeFalse(); // no authed user
});
