<?php

use App\Filament\Pages\ShippingDatabaseSettings;
use App\Models\ShippingDatabaseSetting;
use App\Services\ShippingDatabaseService;

test('a disabled connection is never available', function () {
    ShippingDatabaseSetting::create(['enabled' => false, 'host' => '10.0.0.5']);

    expect(app(ShippingDatabaseService::class)->isAvailable())->toBeFalse();
});

test('stored settings override the shipping connection config', function () {
    ShippingDatabaseSetting::create([
        'enabled' => true,
        'driver' => 'sqlsrv',
        'host' => '10.0.0.5',
        'port' => '1433',
        'database' => 'shipping',
        'username' => 'sa',
        'password' => 'secret',
        'table_name' => 'xCarrierShipping',
        'tracking_column' => 'trackingno',
    ]);

    // Instantiating applies the stored settings to the runtime connection config.
    app(ShippingDatabaseService::class);

    expect(config('database.connections.shipping.host'))->toBe('10.0.0.5')
        ->and(config('database.connections.shipping.database'))->toBe('shipping');
});

test('password is encrypted at rest', function () {
    $s = ShippingDatabaseSetting::create(['host' => 'x', 'password' => 'plaintext']);

    $raw = DB::table('shipping_database_settings')->where('id', $s->id)->value('password');
    expect($raw)->not->toBe('plaintext'); // stored encrypted
    expect($s->fresh()->password)->toBe('plaintext'); // decrypts on read
});

test('test-connection reports a clear message when the sqlsrv driver is missing', function () {
    if (in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
        $this->markTestSkipped('sqlsrv driver present; skipping to avoid a real connect attempt.');
    }

    ShippingDatabaseSetting::create(['enabled' => true, 'host' => '10.0.0.5', 'driver' => 'sqlsrv']);

    $result = app(ShippingDatabaseService::class)->testConnectionDetailed();

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('not installed');
});

test('the settings page is admin-only', function () {
    expect(ShippingDatabaseSettings::canAccess())->toBeFalse(); // no admin user
});
