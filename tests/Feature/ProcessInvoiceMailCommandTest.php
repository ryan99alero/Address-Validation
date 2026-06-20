<?php

use App\Jobs\ProcessMailIntegration;
use App\Models\MailIntegration;
use Illuminate\Support\Facades\Queue;

function makeIntegration(array $overrides = []): MailIntegration
{
    return MailIntegration::create(array_merge([
        'name' => 'UPS',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
        'is_active' => true,
    ], $overrides));
}

test('the command queues a job for each active integration', function () {
    Queue::fake();

    makeIntegration(['name' => 'Active']);
    makeIntegration(['name' => 'Inactive', 'is_active' => false]);

    $this->artisan('mail:process-invoices')->assertSuccessful();

    Queue::assertPushed(ProcessMailIntegration::class, 1);
});

test('the --due flag only queues integrations that are due', function () {
    Queue::fake();

    // Hourly, never processed => due
    makeIntegration(['name' => 'Due', 'poll_minutes' => 60]);
    // Manual only => never due
    makeIntegration(['name' => 'Manual', 'poll_minutes' => 0]);
    // Hourly, processed 5 min ago => not due
    $recent = makeIntegration(['name' => 'Recent', 'poll_minutes' => 60]);
    $recent->update(['last_processed_at' => now()->subMinutes(5)]);

    $this->artisan('mail:process-invoices --due')->assertSuccessful();

    Queue::assertPushed(ProcessMailIntegration::class, 1);
});
