<?php

namespace App\Jobs;

use App\Enums\ChargeDriver;
use App\Exceptions\PaceRequestException;
use App\Models\ChargebackPush;
use App\Services\Chargebacks\ChargebackPusher;
use App\Services\Integrations\PaceApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pushes ONE eligible charge to Pace as a JobCost, safely. Claim-first (the ledger row exists,
 * status `pending`, before the create — the unique key is the mutex), then JobShipment gates, then a
 * retry-off create. A retry verifies the [CB:id] token in Pace BEFORE re-posting, so a timed-out
 * create never double-bills. 4xx = permanent (fail, no retry); timeout/5xx = unverified (retry, then
 * the reconciler resolves it). Every outcome writes a visible ledger disposition.
 *
 * @param  array{carrier_charge_id?:int, carrier_id:int, carrier_invoice_id?:int, invoice_number?:?string, invoice_date?:?string, tracking_number:string, charge_category_id?:int, driver:string, amount:float, ship_date:?string, activity_code:string}  $charge
 */
class PushChargeback implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $charge
     */
    public function __construct(public array $charge, public bool $force = false)
    {
        $this->onQueue('chargebacks');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [1200, 1800]; // ~20 + 30 min ≈ spread over an hour
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pace-chargebacks')];
    }

    public function handle(ChargebackPusher $pusher): void
    {
        $connection = $pusher->activeConnection();
        if (! $pusher->pushEnabled($connection)) {
            return; // master toggle OFF → ignore (no claim, no record)
        }

        $c = $this->charge;
        $txnId = ChargebackPush::identity($c);

        // Claim-first: the row is the mutex. Concurrent/duplicate dispatch AND a re-import that changed
        // ship_date all resolve to the same txn_id, so a charge is claimed (and pushed) exactly once.
        $ledger = ChargebackPush::firstOrCreate(['txn_id' => $txnId], [
            'dedupe_key' => ChargebackPush::dedupeKey((int) $c['carrier_id'], $c['tracking_number'], $c['charge_category_id'] ?? null, (float) $c['amount'], $c['ship_date'] ?? null),
            'carrier_charge_id' => $c['carrier_charge_id'] ?? null,
            'carrier_id' => $c['carrier_id'], 'carrier_invoice_id' => $c['carrier_invoice_id'] ?? null,
            'tracking_number' => $c['tracking_number'], 'charge_category_id' => $c['charge_category_id'] ?? null,
            'driver' => $c['driver'], 'amount' => $c['amount'], 'ship_date' => $c['ship_date'] ?? null,
            'activity_code' => $c['activity_code'], 'status' => ChargebackPush::STATUS_PENDING,
        ]);

        // Terminal states — don't touch (pushed, permanently failed, already-decided skip, or held for
        // review). A quarantined/dismissed row only moves again via an explicit human action (force).
        if (in_array($ledger->status, [ChargebackPush::STATUS_PUSHED, ChargebackPush::STATUS_FAILED,
            ChargebackPush::STATUS_QUARANTINED, ChargebackPush::STATUS_DISMISSED], true)
            || str_starts_with($ledger->status, 'skipped_')) {
            return;
        }

        // Near-duplicate guard: an EXACT dup is impossible (same txn_id → claimed once above), but a
        // re-import that corrected this charge's amount OR recategorized it produces a different txn_id
        // and would post a second JobCost for the same shipment. Hold it for a human instead of posting.
        // `force` (a reviewer's "Push anyway") bypasses this.
        if (! $this->force && ($near = $this->findNearDuplicate($c, $ledger)) !== null) {
            $ledger->update([
                'status' => ChargebackPush::STATUS_QUARANTINED,
                'conflict_with_id' => $near['conflict']->id,
                'conflict_reason' => $near['reason'],
            ]);

            return;
        }

        $ledger->increment('attempts');
        $client = new PaceApiClient($connection);

        // Retry → verify the create didn't already apply before re-posting. Probe by ioID (the txn_id we
        // stamp on every JobCost — an exact structured match), falling back to the legacy notes token for
        // any JobCost created before ioID was populated.
        if ($ledger->attempts > 1) {
            $existingId = $client->findJobCostIdByIoId(ChargebackPush::paceIoId($txnId))
                ?? $client->findJobCostIdByToken('[CB:'.$ledger->id.']');
            if ($existingId !== null) {
                $ledger->update(['status' => ChargebackPush::STATUS_PUSHED, 'pace_jobcost_id' => $existingId, 'pushed_at' => now()]);

                return;
            }
        }

        $shipment = $this->resolveShipment($pusher, $client, $ledger);
        if ($shipment === null) {
            return; // resolveShipment set a skip status
        }

        $notes = $pusher->buildNotes($txnId, $this->noteContext($c, $shipment));
        // Pace returns an EMPTY STRING (not null) for a JobShipment with no @jobPart, so `?? '01'`
        // let it through and created JobCosts with a blank Job Part. Default ANY empty value to the
        // primary part '01', and store the same resolved value on the ledger so it mirrors Pace.
        $jobPart = trim((string) ($shipment['jobPart'] ?? '')) ?: '01';
        $payload = $pusher->buildJobCostPayload([
            'job' => (string) $shipment['job'], 'jobPart' => $jobPart,
            'activityCode' => (string) $c['activity_code'], 'amount' => (float) $c['amount'],
            'tracking' => (string) $c['tracking_number'], 'notes' => $notes, 'txnId' => $txnId,
        ]);
        $ledger->update(array_merge(
            ['notes' => $notes, 'pace_job' => $shipment['job'] ?? null, 'pace_job_part' => $jobPart],
            ChargebackPusher::enrichmentFrom($shipment),
        ));

        // Record-only: the job resolved and is billable, but we deliberately do NOT create a Pace
        // JobCost — the full record (job/customer/CSR/salesperson) is written for the billing export
        // and nothing is posted to the ERP.
        if ($pusher->recordOnly($connection)) {
            $ledger->update(['status' => ChargebackPush::STATUS_RECORDED]);

            return;
        }

        try {
            $result = $client->createObject('JobCost', $payload);
        } catch (PaceRequestException $e) {
            if ($e->isPermanent()) { // 4xx — same payload fails identically; don't retry
                $ledger->update(['status' => ChargebackPush::STATUS_FAILED, 'last_error' => $e->getMessage()]);
                $this->fail($e);

                return;
            }
            $ledger->update(['status' => ChargebackPush::STATUS_UNVERIFIED, 'last_error' => $e->getMessage()]);
            throw $e; // 5xx — retry (which verifies first)
        } catch (Throwable $e) {
            // Timeout / connection error: the create MAY have applied — mark unverified and retry.
            $ledger->update(['status' => ChargebackPush::STATUS_UNVERIFIED, 'last_error' => $e->getMessage()]);
            throw $e;
        }

        $ledger->update([
            'pace_jobcost_id' => $result['id'] ?? $result['primaryKey'] ?? null,
            'response_snapshot' => $result, 'status' => ChargebackPush::STATUS_PUSHED, 'pushed_at' => now(),
        ]);
    }

    /**
     * The correct JobShipment for this tracking. UPS recycles numbers, so several can return; the
     * billable one is where Pace says jobChargesOK=true (open is NOT sufficient — a job can be open
     * but locked to charges). One chargeable → use it; none → not chargeable; many → break the tie
     * with the carton mirror, else ambiguous. Returns null on any skip.
     *
     * @return array<string, mixed>|null
     */
    private function resolveShipment(ChargebackPusher $pusher, PaceApiClient $client, ChargebackPush $ledger): ?array
    {
        // Pass the charge's ship date (falling back to the invoice date) so a recycled tracking resolves
        // to the shipment from THIS period, not a decade-old reuse of the same number.
        $referenceDate = $this->charge['ship_date'] ?? $this->charge['invoice_date'] ?? null;
        $shipments = $pusher->lookupJobShipments($client, (string) $this->charge['tracking_number'], $referenceDate);
        if ($shipments === []) {
            $ledger->update(['status' => ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT]);

            return null;
        }

        // A job is billable only when Pace says its charges are OK (Job/adminStatus/@jobChargesOK) —
        // NOT merely that it's open. A job can be open yet locked to further charges, and we must not
        // bill it. Among a recycled tracking's several JobShipments, the current one is the chargeable
        // one (older recycles are closed/locked).
        $chargeable = array_values(array_filter($shipments, fn (array $s): bool => ($s['jobChargesOK'] ?? null) === true));
        if ($chargeable === []) {
            // No billable job (closed, or open but jobChargesOK=false) → skipped_job_closed. Record the
            // job number(s) AND the customer/CSR/salesperson so this closed-job charge can be downloaded
            // and sent to the responsible reps without any further Pace lookup.
            $ledger->update(array_merge(
                ['status' => ChargebackPush::STATUS_SKIPPED_JOB_CLOSED, 'pace_job' => $this->jobList($shipments)],
                ChargebackPusher::enrichmentFrom(ChargebackPusher::repShipment($shipments) ?? []),
            ));

            return null;
        }
        if (count($chargeable) === 1) {
            return $chargeable[0];
        }

        // Multiple chargeable: prefer the job the carton mirror recorded for this tracking.
        $cartonJob = DB::table('carton_costs')->where('tracking_number', $this->charge['tracking_number'])->value('pace_job_number');
        $matched = array_values(array_filter($chargeable, fn (array $s): bool => (string) ($s['job'] ?? '') === (string) $cartonJob));
        if (count($matched) === 1) {
            return $matched[0];
        }

        $ledger->update(array_merge(
            ['status' => ChargebackPush::STATUS_SKIPPED_AMBIGUOUS, 'pace_job' => $this->jobList($chargeable)],
            ChargebackPusher::enrichmentFrom(ChargebackPusher::repShipment($chargeable) ?? []),
        ));

        return null;
    }

    /**
     * Distinct job number(s) across the given JobShipments, comma-joined — stamped on skipped
     * rows so the ChargebackPushes view shows which job(s) were involved without a Pace lookup.
     *
     * @param  array<int, array<string, mixed>>  $shipments
     */
    private function jobList(array $shipments): ?string
    {
        $jobs = array_values(array_unique(array_filter(array_map(
            fn (array $s): string => trim((string) ($s['job'] ?? '')),
            $shipments
        ))));

        return $jobs === [] ? null : implode(', ', $jobs);
    }

    /**
     * A posted chargeback for the SAME shipment (carrier + tracking + invoice) that shares exactly one
     * of {activity_code, amount} with this charge — the same charge re-imported with a corrected amount
     * or a new category. Genuinely different charges on a shipment differ in BOTH, so they don't match.
     * Needs the invoice to establish "same shipment".
     *
     * @param  array<string, mixed>  $c
     * @return array{conflict: ChargebackPush, reason: string}|null
     */
    private function findNearDuplicate(array $c, ChargebackPush $ledger): ?array
    {
        if (empty($c['carrier_invoice_id'])) {
            return null;
        }

        $conflict = ChargebackPush::where('status', ChargebackPush::STATUS_PUSHED)
            ->where('id', '!=', $ledger->id)
            ->where('carrier_id', $c['carrier_id'])
            ->where('tracking_number', $c['tracking_number'])
            ->where('carrier_invoice_id', $c['carrier_invoice_id'])
            ->where(function ($q) use ($c): void {
                $q->where(fn ($w) => $w->where('activity_code', $c['activity_code'])->where('amount', '!=', (float) $c['amount']))
                    ->orWhere(fn ($w) => $w->where('amount', (float) $c['amount'])->where('activity_code', '!=', $c['activity_code']));
            })
            ->first();

        if (! $conflict) {
            return null;
        }

        return [
            'conflict' => $conflict,
            'reason' => (string) $conflict->activity_code === (string) $c['activity_code']
                ? ChargebackPush::CONFLICT_AMOUNT
                : ChargebackPush::CONFLICT_CATEGORY,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $shipment
     * @return array<string, mixed>
     */
    private function noteContext(array $c, array $shipment): array
    {
        $ctx = [
            'carrier' => DB::table('carriers')->where('id', $c['carrier_id'])->value('name') ?? 'Carrier',
            'label' => ChargeDriver::tryFrom((string) $c['driver'])?->label() ?? 'chargeback',
            'tracking' => $c['tracking_number'], 'invoice' => $c['invoice_number'] ?? null,
            'invoice_date' => $c['invoice_date'] ?? null, 'amount' => $c['amount'],
        ];

        // Best-effort recorded→corrected address (address-correction lines have it; others don't).
        $line = DB::table('carrier_invoice_lines')
            ->where('tracking_number', $c['tracking_number'])
            ->whereNotNull('corrected_city')
            ->first();
        if ($line) {
            $ctx['recorded'] = trim(($line->original_address_1 ?? '').' '.($line->original_city ?? '').' '.($line->original_state ?? '').' '.($line->original_postal ?? ''));
            $ctx['corrected'] = trim(($line->corrected_address_1 ?? '').' '.($line->corrected_city ?? '').' '.($line->corrected_state ?? '').' '.($line->corrected_postal ?? ''));
        }

        return $ctx;
    }

    public function failed(Throwable $e): void
    {
        // Retries exhausted. Leave 'unverified' as-is (the reconciler will resolve by token); only
        // stamp the error so it's visible.
        ChargebackPush::where('txn_id', ChargebackPush::identity($this->charge))->where('status', ChargebackPush::STATUS_PENDING)
            ->update(['status' => ChargebackPush::STATUS_UNVERIFIED, 'last_error' => $e->getMessage()]);
    }
}
