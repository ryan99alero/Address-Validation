<?php

use App\Filament\Resources\SqlConnections\SqlConnectionResource;
use App\Models\SqlConnection;
use App\Services\ShippingDatabaseService;

test('shipping service is unavailable when no active connection exists', function () {
    expect(SqlConnection::active(SqlConnection::PURPOSE_SHIPPING_LOOKUP))->toBeNull();
    expect(app(ShippingDatabaseService::class)->isAvailable())->toBeFalse();
});

test('active connection is resolved by purpose', function () {
    SqlConnection::create(['name' => 'inactive', 'purpose' => SqlConnection::PURPOSE_SHIPPING_LOOKUP, 'is_active' => false, 'host' => 'a']);
    $active = SqlConnection::create(['name' => 'active', 'purpose' => SqlConnection::PURPOSE_SHIPPING_LOOKUP, 'is_active' => true, 'host' => 'b']);

    expect(SqlConnection::active(SqlConnection::PURPOSE_SHIPPING_LOOKUP)?->id)->toBe($active->id);
});

test('field map fills defaults and drives the effective query', function () {
    $c = SqlConnection::create([
        'name' => 'ePace', 'purpose' => SqlConnection::PURPOSE_SHIPPING_LOOKUP, 'is_active' => true,
        'host' => 'x', 'table_name' => 'ShipView',
        'field_map' => ['add1' => 'address1', 'zipcode' => 'zip'], // overrides only
    ]);

    $map = $c->effectiveFieldMap();
    expect($map['add1'])->toBe('address1')   // overridden
        ->and($map['zipcode'])->toBe('zip')  // overridden
        ->and($map['city'])->toBe('city')    // default kept
        ->and($map['tracking'])->toBe('trackingno');

    expect($c->previewQuery())
        ->toContain('FROM ShipView')
        ->toContain('address1')
        ->toContain('WHERE trackingno IN (:trackingNumbers)');
});

test('a custom query overrides the generated preview', function () {
    $c = SqlConnection::create(['name' => 'q', 'custom_query' => 'SELECT 1 FROM t WHERE x IN (:trackingNumbers)']);
    expect($c->previewQuery())->toBe('SELECT 1 FROM t WHERE x IN (:trackingNumbers)');
});

test('password is encrypted at rest', function () {
    $c = SqlConnection::create(['name' => 'p', 'password' => 'plaintext']);
    $raw = DB::table('sql_connections')->where('id', $c->id)->value('password');
    expect($raw)->not->toBe('plaintext');
    expect($c->fresh()->password)->toBe('plaintext');
});

test('test-connection reports a clear message when the sqlsrv driver is missing', function () {
    if (in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
        $this->markTestSkipped('sqlsrv driver present; skipping to avoid a real connect attempt.');
    }

    $c = SqlConnection::create(['name' => 'c', 'is_active' => true, 'host' => '10.0.0.5', 'driver' => 'sqlsrv']);

    $result = app(ShippingDatabaseService::class)->testConnectionDetailed($c);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('not installed');
});

test('the SQL Connections resource is admin-only', function () {
    expect(SqlConnectionResource::canAccess())->toBeFalse();
});
