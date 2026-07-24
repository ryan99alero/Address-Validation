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

    /**
     * Record-only: resolve + write every ledger row but never create a Pace JobCost. Used to
     * re-import and rebuild the chargeback records (job/customer/CSR/salesperson) for the billing
     * export without any external ERP write.
     */
    public function recordOnly(?IntegrationConnection $connection): bool
    {
        return (bool) ($connection?->chargeback_record_only);
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
     * @param  string|null  $referenceDate  the charge's ship/invoice date — narrows a recycled tracking
     *                                      to the shipment from THIS period (see narrowByShipDate)
     * @return array<int, array<string, mixed>>
     */
    public function lookupJobShipments(PaceApiClient $client, string $tracking, ?string $referenceDate = null): array
    {
        $fields = [
            ['name' => 'trackingNumber', 'xpath' => '@trackingNumber'],
            ['name' => 'job', 'xpath' => 'shipment/job/@job'],
            ['name' => 'jobPart', 'xpath' => 'shipment/@jobPart'],
            ['name' => 'customer', 'xpath' => 'shipment/job/@customer'],
            // Customer/CSR/salesperson NAMES traverse the job's FKs in this same query (verified against
            // Pace) — no extra ReadObject call. Fuels the closed-job "who to bill" download.
            ['name' => 'customerName', 'xpath' => 'shipment/job/customer/@custName'],
            ['name' => 'csrName', 'xpath' => 'shipment/job/csr/@name'],
            ['name' => 'salespersonName', 'xpath' => 'shipment/job/salesPerson/@name'],
            ['name' => 'shipDate', 'xpath' => '@actualDate'],
            ['name' => 'openJob', 'xpath' => 'shipment/job/adminStatus/@openJob'],
            ['name' => 'jobChargesOK', 'xpath' => 'shipment/job/adminStatus/@jobChargesOK'],
        ];

        // Pace rejects a date range in the xpathFilter (500 "param2 cannot be null"), so we fetch by
        // tracking and narrow client-side.
        $response = $client->loadValueObjects(
            objectName: 'Carton',
            fields: $fields,
            xpathFilter: "@trackingNumber = '".str_replace("'", "''", $tracking)."'",
            limit: 25,
        );

        $rows = $client->parseValueObjects($response['valueObjects'] ?? [])->all();

        return $this->narrowByShipDate($rows, $referenceDate);
    }

    /**
     * UPS recycles tracking numbers across ~years, so one tracking can match cartons from 2013, 2021,
     * 2026… all under different jobs. Restrict to the shipment from THIS charge's period by comparing
     * the carton ship date (@actualDate) to the charge's ship/invoice date — so we never resolve (or
     * bill) a decade-old recycled job. If no match falls in the window (missing dates, no reference),
     * the full set is returned unchanged rather than dropping a resolvable charge.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function narrowByShipDate(array $rows, ?string $referenceDate): array
    {
        if ($referenceDate === null || count($rows) <= 1) {
            return $rows;
        }
        $ref = strtotime($referenceDate);
        if ($ref === false) {
            return $rows;
        }

        $windowSeconds = 60 * 86400; // ±60 days — comfortably isolates one year's shipment from another
        $near = array_values(array_filter($rows, function (array $r) use ($ref, $windowSeconds): bool {
            $d = isset($r['shipDate']) && $r['shipDate'] !== null && $r['shipDate'] !== '' ? strtotime((string) $r['shipDate']) : false;

            return $d !== false && abs($d - $ref) <= $windowSeconds;
        }));

        return $near !== [] ? $near : $rows;
    }

    /**
     * Pick the representative shipment for stamping customer/CSR/salesperson onto the ledger: the
     * billable one if any (jobChargesOK = true), else the first row — a closed/ambiguous recycle is
     * still the right party to notify about an unbillable charge. Null for an empty set.
     *
     * @param  array<int, array<string, mixed>>  $shipments
     * @return array<string, mixed>|null
     */
    public static function repShipment(array $shipments): ?array
    {
        foreach ($shipments as $shipment) {
            if (($shipment['jobChargesOK'] ?? null) === true) {
                return $shipment;
            }
        }

        return $shipments[0] ?? null;
    }

    /**
     * The customer/CSR/salesperson ledger columns pulled from a resolved Carton->job shipment. Pace
     * returns '' for an unset name, so empties are normalized to null.
     *
     * @param  array<string, mixed>  $shipment
     * @return array{pace_customer_id: ?string, pace_customer_name: ?string, pace_csr_name: ?string, pace_salesperson_name: ?string}
     */
    public static function enrichmentFrom(array $shipment): array
    {
        $clean = fn ($value): ?string => ($value = trim((string) ($value ?? ''))) !== '' ? $value : null;

        return [
            'pace_customer_id' => $clean($shipment['customer'] ?? null),
            'pace_customer_name' => $clean($shipment['customerName'] ?? null),
            'pace_csr_name' => $clean($shipment['csrName'] ?? null),
            'pace_salesperson_name' => $clean($shipment['salespersonName'] ?? null),
        ];
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
