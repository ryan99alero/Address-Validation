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
     * Look up the JobShipment(s) for a tracking number. UPS recycles tracking numbers, so several
     * can return across years — the caller disambiguates by ship date. Returns the raw rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lookupJobShipments(PaceApiClient $client, string $tracking): array
    {
        $fields = [
            ['name' => 'trackingNumber', 'xpath' => '@trackingNumber'],
            ['name' => 'job', 'xpath' => '@job'],
            ['name' => 'jobPart', 'xpath' => '@jobPart'],
            ['name' => 'customer', 'xpath' => 'job/@customer'],
            ['name' => 'shipDate', 'xpath' => '@dateTime'],
            ['name' => 'openJob', 'xpath' => 'job/adminStatus/@openJob'],
        ];

        $response = $client->loadValueObjects(
            objectName: 'JobShipment',
            fields: $fields,
            xpathFilter: "@trackingNumber = '".str_replace("'", "''", $tracking)."'",
            limit: 25,
        );

        return $client->parseValueObjects($response['valueObjects'] ?? [])->all();
    }

    /**
     * The CSR/finance-facing note. The [CB:id] token is FIRST so truncation never eats it — the
     * reconciler matches on it to tell "already applied" from "safe to re-post".
     *
     * @param  array{carrier?:string, tracking?:string, invoice?:string, invoice_date?:string, amount?:float|string, recorded?:?string, corrected?:?string, label?:string}  $ctx
     */
    public function buildNotes(int $ledgerId, array $ctx): string
    {
        $parts = ['[CB:'.$ledgerId.']'];
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
     * @param  array{job:string, jobPart:string, activityCode:string, amount:float|string, tracking:string, notes:string}  $a
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
            'notes' => $a['notes'],
            'startDateTime' => $now->format('Y-m-d\TH:i:s'),
            'endDateTime' => $now->format('Y-m-d\TH:i:s'),
            'postedDate' => $now->format('Y-m-d'),
        ]);
    }
}
