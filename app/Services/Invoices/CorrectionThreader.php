<?php

namespace App\Services\Invoices;

use App\Models\AddressSupersession;
use App\Models\AddressVariant;
use App\Models\AddressVerification;
use App\Models\CorrectedAddress;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a re-correction: address FROM is superseded by address TO. The chain pointer is history-only;
 * the real work is re-pointing FROM's bad-address variants onto the terminal good address so lookups
 * resolve there with zero hot-path change. Shared by the backfill, manual UI apply, and (later)
 * ingest-time threading, so guard + damping + verification side-effects live in exactly one place.
 */
class CorrectionThreader
{
    /**
     * Supersede $from with $to (resolved to its terminal). Returns the applied event, or null when
     * damping/reversal makes it a no-op (a pending_review event is recorded in the damping case).
     *
     * @param  array{trigger?: string, carrier_id?: ?int, carrier_invoice_line_id?: ?int, date?: ?string, guard_result?: ?array, applied_by?: ?int}  $evidence
     */
    public function thread(CorrectedAddress $from, CorrectedAddress $to, array $evidence = []): ?AddressSupersession
    {
        return DB::transaction(function () use ($from, $to, $evidence): ?AddressSupersession {
            $from = CorrectedAddress::query()->lockForUpdate()->find($from->id);
            $to = CorrectedAddress::query()->lockForUpdate()->find($to->id);
            if ($from === null || $to === null) {
                return null;
            }

            $terminal = $this->resolveTargetOrReverse($from, $to);
            if ($terminal === null) {
                return null;
            }

            $trigger = $evidence['trigger'] ?? AddressSupersession::TRIGGER_MANUAL;

            if ($this->flips($from->id, $terminal->id) >= (int) config('correction_cache.flip_flop_threshold', 2)) {
                return $this->recordEvent($from, $terminal, $trigger, AddressSupersession::STATUS_PENDING_REVIEW, $evidence);
            }

            $oldSnapshot = $this->snapshot($from);
            $newSnapshot = $this->snapshot($terminal);
            $date = isset($evidence['date']) && $evidence['date'] ? Carbon::parse($evidence['date']) : now();

            $this->applySupersede($from, $terminal, $trigger, $date, $evidence['carrier_id'] ?? null);

            return AddressSupersession::create([
                'old_corrected_address_id' => $from->id,
                'new_corrected_address_id' => $terminal->id,
                'old_snapshot' => $oldSnapshot,
                'new_snapshot' => $newSnapshot,
                'carrier_id' => $evidence['carrier_id'] ?? null,
                'carrier_invoice_line_id' => $evidence['carrier_invoice_line_id'] ?? null,
                'trigger' => $trigger,
                'status' => AddressSupersession::STATUS_APPLIED,
                'guard_result' => $evidence['guard_result'] ?? null,
                'detected_at' => now(),
                'applied_at' => now(),
                'applied_by' => $evidence['applied_by'] ?? null,
            ]);
        });
    }

    /**
     * Apply a pending-review event from the UI: perform the supersede and flip the event itself to
     * applied (rather than logging a second event). Returns false if the event isn't applicable.
     */
    public function applyPending(AddressSupersession $event, ?int $userId): bool
    {
        return DB::transaction(function () use ($event, $userId): bool {
            if ($event->status !== AddressSupersession::STATUS_PENDING_REVIEW) {
                return false;
            }

            $from = CorrectedAddress::query()->lockForUpdate()->find($event->old_corrected_address_id);
            $to = CorrectedAddress::query()->lockForUpdate()->find($event->new_corrected_address_id);
            if ($from === null || $to === null) {
                return false;
            }

            $terminal = $this->resolveTargetOrReverse($from, $to);
            if ($terminal === null) {
                return false;
            }

            $this->applySupersede($from, $terminal, $event->trigger ?: AddressSupersession::TRIGGER_MANUAL, now(), $event->carrier_id);

            $event->update([
                'status' => AddressSupersession::STATUS_APPLIED,
                'new_corrected_address_id' => $terminal->id,
                'applied_at' => now(),
                'applied_by' => $userId,
            ]);

            return true;
        });
    }

    /**
     * Resolve $to to its live terminal; if that walk comes back to $from it's a reversal — break the
     * back-reference so we never form a cycle, then crown $to. Returns null when the result would be a
     * no-op (from == terminal).
     */
    private function resolveTargetOrReverse(CorrectedAddress $from, CorrectedAddress $to): ?CorrectedAddress
    {
        if ($from->id === $to->id) {
            return null;
        }

        $terminal = $to->resolveTerminal();
        if ($terminal->id === $from->id) {
            $to->update(['superseded_by_id' => null, 'superseded_at' => null, 'supersede_reason' => null]);
            $terminal = $to->fresh();
        }

        return $from->id === $terminal->id ? null : $terminal;
    }

    /**
     * The state mutation shared by thread() and applyPending(): supersede $from with $terminal,
     * re-point variants, recount, and stamp per-carrier verification (old drifted, new verified).
     */
    private function applySupersede(CorrectedAddress $from, CorrectedAddress $terminal, string $trigger, CarbonInterface $date, ?int $carrierId): void
    {
        $from->update([
            'superseded_by_id' => $terminal->id,
            'superseded_at' => $date,
            'supersede_reason' => $trigger,
        ]);

        AddressVariant::repointAll($from->id, $terminal->id);
        $from->update(['variant_count' => $from->variants()->count()]);
        $terminal->update(['variant_count' => $terminal->variants()->count()]);

        if (! empty($carrierId)) {
            $this->markDrifted($from->id, (int) $carrierId, $this->snapshot($terminal));
            $this->markVerified($terminal->id, (int) $carrierId, $date, AddressVerification::SOURCE_INVOICE);
        }
    }

    /**
     * Record a non-applied event (pending review or rejected garbage) without changing any pointer.
     *
     * @param  array{carrier_id?: ?int, carrier_invoice_line_id?: ?int, guard_result?: ?array}  $evidence
     */
    public function recordEvent(?CorrectedAddress $from, ?CorrectedAddress $to, string $trigger, string $status, array $evidence = []): AddressSupersession
    {
        return AddressSupersession::create([
            'old_corrected_address_id' => $from?->id,
            'new_corrected_address_id' => $to?->id,
            'old_snapshot' => $from ? $this->snapshot($from) : null,
            'new_snapshot' => $to ? $this->snapshot($to) : null,
            'carrier_id' => $evidence['carrier_id'] ?? null,
            'carrier_invoice_line_id' => $evidence['carrier_invoice_line_id'] ?? null,
            'trigger' => $trigger,
            'status' => $status,
            'guard_result' => $evidence['guard_result'] ?? null,
            'detected_at' => now(),
        ]);
    }

    private function flips(int $a, int $b): int
    {
        return AddressSupersession::query()
            ->where('status', AddressSupersession::STATUS_APPLIED)
            ->where(function ($q) use ($a, $b): void {
                $q->where(fn ($q) => $q->where('old_corrected_address_id', $a)->where('new_corrected_address_id', $b))
                    ->orWhere(fn ($q) => $q->where('old_corrected_address_id', $b)->where('new_corrected_address_id', $a));
            })
            ->count();
    }

    private function markVerified(int $addressId, int $carrierId, CarbonInterface $date, string $source): void
    {
        $v = AddressVerification::firstOrNew(['corrected_address_id' => $addressId, 'carrier_id' => $carrierId]);
        if ($v->verified_at === null || $date->gt($v->verified_at)) {
            $v->status = AddressVerification::STATUS_VERIFIED;
            $v->verified_at = $date;
            $v->source = $source;
        }
        $v->checked_at = now();
        $v->save();
    }

    /**
     * @param  array<string, mixed>  $carrierWantedForm
     */
    private function markDrifted(int $addressId, int $carrierId, array $carrierWantedForm): void
    {
        $v = AddressVerification::firstOrNew(['corrected_address_id' => $addressId, 'carrier_id' => $carrierId]);
        $v->status = AddressVerification::STATUS_DRIFTED;
        $v->verified_at = null;
        $v->result_snapshot = $carrierWantedForm;
        $v->checked_at = now();
        $v->save();
    }

    /**
     * @return array{address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string, postal_ext: ?string}
     */
    private function snapshot(CorrectedAddress $a): array
    {
        return [
            'address_1' => $a->address_1,
            'address_2' => $a->address_2,
            'city' => $a->city,
            'state' => $a->state,
            'postal' => $a->postal,
            'postal_ext' => $a->postal_ext,
        ];
    }
}
