<?php

use App\Models\MailIntegration;

test('imap and zip passwords are stored encrypted and read back', function () {
    $integration = MailIntegration::create([
        'name' => 'UPS Invoices',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
    ]);
    $integration->setCredentials([
        'imap_password' => 'secret-imap',
        'zip_password' => 'static-zip-pw',
    ]);
    $integration->save();

    // Raw column must not contain the plaintext.
    $raw = $integration->getRawOriginal('credentials');
    expect($raw)->not->toContain('secret-imap');
    expect($raw)->not->toContain('static-zip-pw');

    $fresh = $integration->fresh();
    expect($fresh->getImapPassword())->toBe('secret-imap');
    expect($fresh->getZipPassword())->toBe('static-zip-pw');
});

test('credentials are hidden from array/json serialization', function () {
    $integration = MailIntegration::create([
        'name' => 'UPS Invoices',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
    ]);
    $integration->setCredentials(['imap_password' => 'x']);
    $integration->save();

    expect($integration->fresh()->toArray())->not->toHaveKey('credentials');
});

test('isDueForPoll respects active flag, frequency, and last processed time', function () {
    $base = [
        'name' => 'UPS',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
    ];

    // Manual only (0) => never due
    expect(MailIntegration::make($base + ['is_active' => true, 'poll_minutes' => 0])->isDueForPoll())->toBeFalse();

    // Inactive => never due
    expect(MailIntegration::make($base + ['is_active' => false, 'poll_minutes' => 60])->isDueForPoll())->toBeFalse();

    // Active, hourly, never processed => due
    expect(MailIntegration::make($base + ['is_active' => true, 'poll_minutes' => 60])->isDueForPoll())->toBeTrue();

    // Active, hourly, processed 10 min ago => not due
    $recent = MailIntegration::make($base + ['is_active' => true, 'poll_minutes' => 60]);
    $recent->last_processed_at = now()->subMinutes(10);
    expect($recent->isDueForPoll())->toBeFalse();

    // Active, hourly, processed 90 min ago => due
    $stale = MailIntegration::make($base + ['is_active' => true, 'poll_minutes' => 60]);
    $stale->last_processed_at = now()->subMinutes(90);
    expect($stale->isDueForPoll())->toBeTrue();
});

test('markChecked records status and timestamp', function () {
    $integration = MailIntegration::create([
        'name' => 'UPS Invoices',
        'imap_host' => 'mail.example.com',
        'imap_username' => 'invoices@example.com',
    ]);

    $integration->markChecked('error', 'Authentication failed');

    $fresh = $integration->fresh();
    expect($fresh->last_status)->toBe('error');
    expect($fresh->last_error)->toBe('Authentication failed');
    expect($fresh->last_checked_at)->not->toBeNull();
});
