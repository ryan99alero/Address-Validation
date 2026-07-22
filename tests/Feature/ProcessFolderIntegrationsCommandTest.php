<?php

use App\Jobs\ProcessFolderIntegration;
use App\Models\FolderIntegration;
use Illuminate\Support\Facades\Queue;

function makeFolder(array $overrides = []): FolderIntegration
{
    return FolderIntegration::create(array_merge([
        'name' => 'FedEx Share',
        'connection_type' => FolderIntegration::TYPE_LOCAL,
        'base_path' => '/mnt/invoices',
        'is_active' => true,
    ], $overrides));
}

test('the command queues a scan job for each active folder integration', function () {
    Queue::fake();

    makeFolder(['name' => 'Active']);
    makeFolder(['name' => 'Inactive', 'is_active' => false]);

    $this->artisan('folders:process')->assertSuccessful();

    Queue::assertPushed(ProcessFolderIntegration::class, 1);
});

test('the --due flag only queues folders that are due per their poll frequency', function () {
    Queue::fake();

    // Every 12 hours, never scanned => due
    makeFolder(['name' => 'Due', 'poll_minutes' => 720]);
    // Manual only => never due
    makeFolder(['name' => 'Manual', 'poll_minutes' => 0]);
    // Every 12 hours, checked 5 min ago => not due
    $recent = makeFolder(['name' => 'Recent', 'poll_minutes' => 720]);
    $recent->update(['last_checked_at' => now()->subMinutes(5)]);

    $this->artisan('folders:process --due')->assertSuccessful();

    Queue::assertPushed(ProcessFolderIntegration::class, 1);
});

test('isDueForPoll keys off last_checked_at, not last_processed_at', function () {
    // A folder scanned within its window is NOT due, even though it last IMPORTED a file long ago
    // (a scan that finds no new files still stamps last_checked_at). This is the bug fix: the old
    // "Last Run" (last_processed_at) could be days stale while scans were actually running.
    $folder = makeFolder(['poll_minutes' => 720]);
    $folder->update([
        'last_checked_at' => now()->subMinutes(30),
        'last_processed_at' => now()->subDays(5),
    ]);
    expect($folder->isDueForPoll())->toBeFalse();

    // Once the poll window elapses since the last check, it becomes due again.
    $folder->update(['last_checked_at' => now()->subHours(13)]);
    expect($folder->isDueForPoll())->toBeTrue();

    // Inactive or manual-only folders are never due.
    expect(makeFolder(['is_active' => false, 'poll_minutes' => 60])->isDueForPoll())->toBeFalse();
    expect(makeFolder(['poll_minutes' => 0])->isDueForPoll())->toBeFalse();
});
