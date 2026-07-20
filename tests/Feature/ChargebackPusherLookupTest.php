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

    // Every field the caller consumes is traversed from Carton -> shipment -> job.
    $byName = collect($client->lastCall['fields'])->keyBy('name')->map(fn ($f) => $f['xpath']);
    expect($byName['job'])->toBe('shipment/job/@job')
        ->and($byName['jobChargesOK'])->toBe('shipment/job/adminStatus/@jobChargesOK')
        ->and($byName['openJob'])->toBe('shipment/job/adminStatus/@openJob')
        ->and($byName['customer'])->toBe('shipment/job/@customer')
        ->and($byName['jobPart'])->toBe('shipment/@jobPart');

    // Return shape is preserved for resolveShipment (object-agnostic keys).
    expect($rows)->toBe([['job' => 'M244674', 'jobChargesOK' => true, 'jobPart' => '01']]);
});

test('an IntegrationConnection is not required to build the recording double', function () {
    // Guards the empty constructor above from drifting — the double must never touch a connection.
    expect(fn () => new RecordingPaceClient)->not->toThrow(Throwable::class)
        ->and(new IntegrationConnection(['driver' => 'pace']))->toBeInstanceOf(IntegrationConnection::class);
});
