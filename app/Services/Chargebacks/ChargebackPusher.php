<?php

namespace App\Services\Chargebacks;

use App\Models\IntegrationConnection;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Support\Carbon;

/**
 * Builds and sends the Pace JobCost for a customer chargeback. Pure mechanics — the claim-first
 * ledger protocol and retry/verify live in the PushChargeback job; this holds the payload shape,
 * the JobShipment lookup (recycled-tracking aware), and the create call.
 */
class ChargebackPusher
{
    /** Constants mirrored from a real JobCost record (business fields Pace's workflow doesn't own). */
    private const CONSTANTS = [
        'chargeClass' => 9,
        'transactionType' => 6,
        'journalCode' => 'DE',
        'billRate' => 1,
    ];

    public function activeConnection(): ?IntegrationConnection
    {
        return IntegrationConnection::byDriver(IntegrationConnection::DRIVER_PACE)->active()->first();
    }

    public function pushEnabled(?IntegrationConnection $connection): bool
    {
        return (bool) ($connection?->chargeback_push_enabled);
    }

    /** activityCode recorded = the Fee Category's cost center, falling back to the driver's. */
    public function resolveActivityCode(?string $categoryCostCenter, ?string $driverCostCenter): ?string
    {
        return $categoryCostCenter ?: $driverCostCenter;
    }

    /**
     * Resolve the job shipment(s) for a carrier tracking number.
     *
     * Carrier tracking numbers live on Pace's CARTON object (one per package), NOT on JobShipment:
     * a JobShipment's own @trackingNumber only ever carries its primary package, so multi-carton and
     * most FedEx trackings never match `JobShipment/@trackingNumber` and were falsely recorded
     * `skipped_no_jobshipment` (a valid, empty totalRecords:0 response — indistinguishable from a
     * fake tracking). Resolve via Carton instead — the same object the carton-cost mirror uses — and
     * traverse Carton -> shipment -> job. UPS recycles tracking numbers, so several rows can return
     * across years; the caller disambiguates by charge-ability. `jobChargesOK`
     * (shipment/job/adminStatus/@jobChargesOK) is the authoritative "may we bill this job?" flag — it
     * is NOT the same as `openJob`; a job can be open yet locked to further charges. `openJob` is kept
     * only for audit/diagnostic context. All xpaths below are confirmed against the live Pace object
     * model; the returned key names are preserved so the caller is object-agnostic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lookupJobShipments(PaceApiClient $client, string $tracking): array
    {
        $fields = [
            ['name' => 'trackingNumber', 'xpath' => '@trackingNumber'],
            ['name' => 'job', 'xpath' => 'shipment/job/@job'],
            ['name' => 'jobPart', 'xpath' => 'shipment/@jobPart'],
            ['name' => 'customer', 'xpath' => 'shipment/job/@customer'],
            ['name' => 'openJob', 'xpath' => 'shipment/job/adminStatus/@openJob'],
            ['name' => 'jobChargesOK', 'xpath' => 'shipment/job/adminStatus/@jobChargesOK'],
        ];

        $response = $client->loadValueObjects(
            objectName: 'Carton',
            fields: $fields,
            xpathFilter: "@trackingNumber = '".str_replace("'", "''", $tracking)."'",
            limit: 25,
        );

        return $client->parseValueObjects($response['valueObjects'] ?? [])->all();
    }

    /**
     * The CSR/finance-facing note. The [CB:txn_id] token is FIRST (and mirrors the structured ioID
     * field) so a human can read it; recovery matches on ioID, not this text.
     *
     * @param  array{carrier?:string, tracking?:string, invoice?:string, invoice_date?:string, amount?:float|string, recorded?:?string, corrected?:?string, label?:string}  $ctx
     */
    public function buildNotes(string $txnId, array $ctx): string
    {
        $parts = ['[CB:'.$txnId.']'];
        $parts[] = trim(($ctx['carrier'] ?? 'Carrier').' '.($ctx['label'] ?? 'chargeback').'.');
        if (! empty($ctx['tracking'])) {
            $parts[] = 'Tracking '.$ctx['tracking'].'.';
        }
        if (! empty($ctx['invoice'])) {
            $parts[] = 'Invoice '.$ctx['invoice'].(! empty($ctx['invoice_date']) ? ' ('.$ctx['invoice_date'].')' : '').'.';
        }
        if (isset($ctx['amount'])) {
            $parts[] = '$'.number_format((float) $ctx['amount'], 2).'.';
        }
        if (! empty($ctx['recorded']) && ! empty($ctx['corrected'])) {
            $parts[] = 'Ship-to corrected by carrier: "'.$ctx['recorded'].'" -> "'.$ctx['corrected'].'".';
        }

        return mb_substr(implode(' ', $parts), 0, 500);
    }

    /**
     * Assemble the JobCost create payload. Deliberately omits posting-state fields (posted /
     * postingStatus / autoPost / postable) — Pace's Create-Costs workflow owns those. Dates are the
     * post moment (now).
     *
     * @param  array{job:string, jobPart:string, activityCode:string, amount:float|string, tracking:string, notes:string, txnId:string}  $a
     * @return array<string, mixed>
     */
    public function buildJobCostPayload(array $a): array
    {
        $now = Carbon::now();

        return array_merge(self::CONSTANTS, [
            'job' => $a['job'],
            'jobPart' => $a['jobPart'],
            'activityCode' => $a['activityCode'],
            'cost' => number_format((float) $a['amount'], 2, '.', ''),
            'actualCost' => number_format((float) $a['amount'], 2, '.', ''),
            'sourceID' => $a['tracking'],
            // The stable txn_id in Pace's external-transaction field: an exact structured idempotency
            // key the recovery probe matches on, so a crash between Pace-commit and our-save can adopt
            // the existing JobCost instead of posting a second one.
            'ioID' => $a['txnId'],
            'notes' => $a['notes'],
            'startDateTime' => $now->format('Y-m-d\TH:i:s'),
            'endDateTime' => $now->format('Y-m-d\TH:i:s'),
            'postedDate' => $now->format('Y-m-d'),
        ]);
    }
}
