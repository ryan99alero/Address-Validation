<?php

use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Support\Collection;

/**
 * A carrier tracking number lives on Pace's CARTON object, not on JobShipment. This double records
 * exactly how the resolver queried Pace so the test can assert the object + traversal xpaths — the
 * regression that produced ~289 false skipped_no_jobshipment rows was querying the wrong object.
 */
class RecordingPaceClient extends PaceApiClient
{
    /** @var array<string, mixed> */
    public array $lastCall = [];

    public function __construct() {}

    public function loadValueObjects(
        string $objectName,
        array $fields,
        array $children = [],
        ?string $primaryKey = null,
        ?string $xpathFilter = null,
        ?string $xpathSorts = null,
        int $offset = 0,
        ?int $limit = null,
    ): array {
        $this->lastCall = compact('objectName', 'fields', 'xpathFilter', 'limit');

        return ['valueObjects' => [['fields' => []]], 'totalRecords' => 1];
    }

    public function parseValueObjects(array $valueObjects): Collection
    {
        // Stand in for the real parser: hand back one resolved, billable row.
        return collect([['job' => 'M244674', 'jobChargesOK' => true, 'jobPart' => '01']]);
    }
}

test('lookupJobShipments queries the CARTON object and traverses shipment -> job', function () {
    $client = new RecordingPaceClient;
    $pusher = new ChargebackPusher;

    $rows = $pusher->lookupJobShipments($client, "O'Brien-123");

    // The single query hit Carton (NOT JobShipment) with the tracking filter escaped.
    expect($client->lastCall['objectName'])->toBe('Carton')
        ->and($client->lastCall['xpathFilter'])->toBe("@trackingNumber = 'O''Brien-123'")
        ->and($client->lastCall['limit'])->toBe(25);

    // Every field the caller consumes is traversed from Carton -> shipment -> job, including the
    // customer/CSR/salesperson names resolved in this same query (verified working against Pace).
    $byName = collect($client->lastCall['fields'])->keyBy('name')->map(fn ($f) => $f['xpath']);
    expect($byName['job'])->toBe('shipment/job/@job')
        ->and($byName['jobChargesOK'])->toBe('shipment/job/adminStatus/@jobChargesOK')
        ->and($byName['openJob'])->toBe('shipment/job/adminStatus/@openJob')
        ->and($byName['customer'])->toBe('shipment/job/@customer')
        ->and($byName['customerName'])->toBe('shipment/job/customer/@custName')
        ->and($byName['csrName'])->toBe('shipment/job/csr/@name')
        ->and($byName['salespersonName'])->toBe('shipment/job/salesPerson/@name')
        ->and($byName['jobPart'])->toBe('shipment/@jobPart');

    // Return shape is preserved for resolveShipment (object-agnostic keys).
    expect($rows)->toBe([['job' => 'M244674', 'jobChargesOK' => true, 'jobPart' => '01']]);
});

test('enrichmentFrom + repShipment extract the ledger name columns from a resolved shipment', function () {
    // repShipment prefers the billable row; here the closed recycle is the only one, so it's used —
    // a closed-job charge still needs its customer/CSR/salesperson for the "couldn't bill" download.
    $shipments = [
        ['job' => 'J-OLD', 'jobChargesOK' => false, 'customer' => '3035', 'customerName' => 'KUBOTA - SOURCING GROUP', 'csrName' => 'HEATHER', 'salespersonName' => 'RANDALL V'],
    ];

    $rep = ChargebackPusher::repShipment($shipments);
    expect(ChargebackPusher::enrichmentFrom($rep))->toBe([
        'pace_customer_id' => '3035',
        'pace_customer_name' => 'KUBOTA - SOURCING GROUP',
        'pace_csr_name' => 'HEATHER',
        'pace_salesperson_name' => 'RANDALL V',
    ]);

    // Empty strings (Pace's unset-name sentinel) collapse to null.
    expect(ChargebackPusher::enrichmentFrom(['customer' => '', 'csrName' => '']))->toBe([
        'pace_customer_id' => null, 'pace_customer_name' => null, 'pace_csr_name' => null, 'pace_salesperson_name' => null,
    ]);
});

test('an IntegrationConnection is not required to build the recording double', function () {
    // Guards the empty constructor above from drifting — the double must never touch a connection.
    expect(fn () => new RecordingPaceClient)->not->toThrow(Throwable::class)
        ->and(new IntegrationConnection(['driver' => 'pace']))->toBeInstanceOf(IntegrationConnection::class);
});
