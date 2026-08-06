<?php

use App\Jobs\ProcessPaceAddressCorrection;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\IntegrationConnection;
use App\Models\IntegrationFieldMapping;
use App\Models\IntegrationObject;
use App\Models\SystemLog;
use App\Services\AddressValidationService;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function paceConnectionWithZipPush(bool $dryRun = false): IntegrationConnection
{
    $conn = IntegrationConnection::create([
        'name' => 'Pace API',
        'driver' => IntegrationConnection::DRIVER_PACE,
        'base_url' => 'https://pace.test/rpc/rest',
        'auth_type' => 'none',
        'is_active' => true,
        'dry_run' => $dryRun,
        'validation_carriers' => ['fedex'],
        'webhook_token' => 'tok-'.bin2hex(random_bytes(3)),
        'timeout_seconds' => 30,
        'retry_attempts' => 1,
    ]);

    $contact = IntegrationObject::create([
        'connection_id' => $conn->id,
        'object_name' => 'Contact',
        'display_name' => 'Contact',
    ]);
    IntegrationFieldMapping::create([
        'object_id' => $contact->id,
        'local_field' => 'zip',
        'local_type' => 'string',
        'external_field' => 'zip',
        'external_xpath' => '@zip',
        'external_type' => 'String',
        'sync_on_push' => true,
    ]);

    return $conn;
}

/** A validator that corrects the ZIP to ZIP+4 — produces exactly one pushable change. */
function validationReturningZipPlus4(): AddressValidationService
{
    $mock = Mockery::mock(AddressValidationService::class);
    $mock->shouldReceive('validateAddress')->andReturnUsing(function (Address $a, $carrier = null) {
        $a->output_address_1 = $a->input_address_1;
        $a->output_city = $a->input_city;
        $a->output_state = $a->input_state;
        $a->output_postal = '67460';
        $a->output_postal_ext = '8139';
        $a->is_residential = false;
        $a->validation_source = 'fedex_api';

        return $a;
    });

    return $mock;
}

function correctionPayload(): array
{
    return [
        'shipment_id' => 7152203,
        'contact_id' => 6451725,
        'address1' => '123 Main St',
        'city' => 'Moundridge',
        'state' => 'KS',
        'zip' => '67460',
    ];
}

beforeEach(function () {
    Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);
});

test('pushes the correction and flags the JobShipment when it is Planned', function () {
    Http::fake([
        '*readJobShipment*' => Http::response(['planned' => true]),
        '*updateContact*' => Http::response([]),
        '*updateJobShipment*' => Http::response([]),
        '*' => Http::response([]),
    ]);

    $conn = paceConnectionWithZipPush();
    (new ProcessPaceAddressCorrection($conn->id, correctionPayload()))->handle(validationReturningZipPlus4());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'updateContact'));
    // The JobShipment is flagged u_addressCorrected = true on an actual correction.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'updateJobShipment')
        && ($request->data()['u_addressCorrected'] ?? null) === true);

    $log = SystemLog::where('type', 'pace_address_correction')->latest('id')->first();
    expect($log->status)->toBe('success')
        ->and($log->summary)->toContain('corrected & pushed')
        ->and($log->metadata['pushed'])->toBeTrue()
        ->and($log->metadata['pushed_at'])->not->toBeNull()
        ->and($log->metadata['shipment_planned'])->toBeTrue()
        ->and($log->metadata['address_corrected_flagged'])->toBeTrue();
});

test('does NOT push or flag when the JobShipment is not Planned', function () {
    Http::fake([
        '*readJobShipment*' => Http::response(['planned' => false]),
        '*updateContact*' => Http::response([]),
        '*updateJobShipment*' => Http::response([]),
        '*' => Http::response([]),
    ]);

    $conn = paceConnectionWithZipPush();
    (new ProcessPaceAddressCorrection($conn->id, correctionPayload()))->handle(validationReturningZipPlus4());

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'updateContact'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'updateJobShipment'));

    $log = SystemLog::where('type', 'pace_address_correction')->latest('id')->first();
    expect($log->status)->toBe('skipped')
        ->and($log->summary)->toContain('not Planned')
        ->and($log->metadata['pushed'])->toBeFalse()
        ->and($log->metadata['planned_blocked'])->toBeTrue()
        ->and($log->metadata['address_corrected_flagged'])->toBeFalse();
});

test('a failed JobShipment flag write does not fail the correction that already landed', function () {
    Http::fake([
        '*readJobShipment*' => Http::response(['planned' => true]),
        '*updateContact*' => Http::response([]),
        '*updateJobShipment*' => Http::response(['error' => 'boom'], 500),
        '*' => Http::response([]),
    ]);

    $conn = paceConnectionWithZipPush();
    (new ProcessPaceAddressCorrection($conn->id, correctionPayload()))->handle(validationReturningZipPlus4());

    // The Contact correction still succeeded (pushed=true); only the JobShipment flag failed.
    $log = SystemLog::where('type', 'pace_address_correction')->latest('id')->first();
    expect($log->status)->toBe('success')
        ->and($log->summary)->toContain('corrected & pushed')
        ->and($log->metadata['pushed'])->toBeTrue()
        ->and($log->metadata['address_corrected_flagged'])->toBeFalse();
});

test('shipmentIsPlanned prefers a planned flag already in the payload (no Pace read)', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $conn = paceConnectionWithZipPush();
    $client = new PaceApiClient($conn);
    $job = new ProcessPaceAddressCorrection($conn->id, []);
    $method = new ReflectionMethod($job, 'shipmentIsPlanned');

    expect($method->invoke($job, $client, ['planned' => 'true'], 999))->toBeTrue()
        ->and($method->invoke($job, $client, ['planned' => 'false'], 999))->toBeFalse();

    Http::assertNothingSent();
});

test('shipmentIsPlanned returns null (blocks) when planned cannot be determined', function () {
    Http::fake(['*readJobShipment*' => Http::response(['id' => 1]), '*' => Http::response([])]);
    $conn = paceConnectionWithZipPush();
    $client = new PaceApiClient($conn);
    $job = new ProcessPaceAddressCorrection($conn->id, []);
    $method = new ReflectionMethod($job, 'shipmentIsPlanned');

    expect($method->invoke($job, $client, [], 7152203))->toBeNull() // read has no 'planned'
        ->and($method->invoke($job, $client, [], null))->toBeNull(); // no shipment id
});
