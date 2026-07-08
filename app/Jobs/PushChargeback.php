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
    public function __construct(public array $charge)
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
        $key = ChargebackPush::dedupeKey((int) $c['carrier_id'], $c['tracking_number'], $c['charge_category_id'] ?? null, (float) $c['amount'], $c['ship_date'] ?? null);

        // Claim-first: the row is the mutex. Concurrent/duplicate dispatch loses on the unique key.
        $ledger = ChargebackPush::firstOrCreate(['dedupe_key' => $key], [
            'carrier_charge_id' => $c['carrier_charge_id'] ?? null,
            'carrier_id' => $c['carrier_id'], 'carrier_invoice_id' => $c['carrier_invoice_id'] ?? null,
            'tracking_number' => $c['tracking_number'], 'charge_category_id' => $c['charge_category_id'] ?? null,
            'driver' => $c['driver'], 'amount' => $c['amount'], 'ship_date' => $c['ship_date'] ?? null,
            'activity_code' => $c['activity_code'], 'status' => ChargebackPush::STATUS_PENDING,
        ]);

        // Terminal states — don't touch (pushed, permanently failed, or already-decided skip).
        if (in_array($ledger->status, [ChargebackPush::STATUS_PUSHED, ChargebackPush::STATUS_FAILED], true)
            || str_starts_with($ledger->status, 'skipped_')) {
            return;
        }

        $ledger->increment('attempts');
        $client = new PaceApiClient($connection);
        $token = '[CB:'.$ledger->id.']';

        // Retry → verify the create didn't already apply before re-posting.
        if ($ledger->attempts > 1 && ($existingId = $client->findJobCostIdByToken($token)) !== null) {
            $ledger->update(['status' => ChargebackPush::STATUS_PUSHED, 'pace_jobcost_id' => $existingId, 'pushed_at' => now()]);

            return;
        }

        $shipment = $this->resolveShipment($pusher, $client, $ledger);
        if ($shipment === null) {
            return; // resolveShipment set a skip status
        }

        $notes = $pusher->buildNotes($ledger->id, $this->noteContext($c, $shipment));
        $payload = $pusher->buildJobCostPayload([
            'job' => (string) $shipment['job'], 'jobPart' => (string) ($shipment['jobPart'] ?? '01'),
            'activityCode' => (string) $c['activity_code'], 'amount' => (float) $c['amount'],
            'tracking' => (string) $c['tracking_number'], 'notes' => $notes,
        ]);
        $ledger->update(['notes' => $notes, 'pace_job' => $shipment['job'] ?? null, 'pace_job_part' => $shipment['jobPart'] ?? null, 'pace_customer_id' => $shipment['customer'] ?? null]);

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
     * current job is the OPEN one (older recycles are closed). One open → use it; none → job closed;
     * many open → break the tie with the carton mirror, else ambiguous. Returns null on any skip.
     *
     * @return array<string, mixed>|null
     */
    private function resolveShipment(ChargebackPusher $pusher, PaceApiClient $client, ChargebackPush $ledger): ?array
    {
        $shipments = $pusher->lookupJobShipments($client, (string) $this->charge['tracking_number']);
        if ($shipments === []) {
            $ledger->update(['status' => ChargebackPush::STATUS_SKIPPED_NO_JOBSHIPMENT]);

            return null;
        }

        $open = array_values(array_filter($shipments, fn (array $s): bool => ($s['openJob'] ?? null) === true));
        if ($open === []) {
            $ledger->update(['status' => ChargebackPush::STATUS_SKIPPED_JOB_CLOSED]);

            return null;
        }
        if (count($open) === 1) {
            return $open[0];
        }

        // Multiple open: prefer the job the carton mirror recorded for this tracking.
        $cartonJob = DB::table('carton_costs')->where('tracking_number', $this->charge['tracking_number'])->value('pace_job_number');
        $matched = array_values(array_filter($open, fn (array $s): bool => (string) ($s['job'] ?? '') === (string) $cartonJob));
        if (count($matched) === 1) {
            return $matched[0];
        }

        $ledger->update(['status' => ChargebackPush::STATUS_SKIPPED_AMBIGUOUS]);

        return null;
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
        $c = $this->charge;
        $key = ChargebackPush::dedupeKey((int) $c['carrier_id'], $c['tracking_number'], $c['charge_category_id'] ?? null, (float) $c['amount'], $c['ship_date'] ?? null);
        ChargebackPush::where('dedupe_key', $key)->where('status', ChargebackPush::STATUS_PENDING)
            ->update(['status' => ChargebackPush::STATUS_UNVERIFIED, 'last_error' => $e->getMessage()]);
    }
}
