<?php

namespace App\Jobs;

use App\Models\Address;
use App\Models\AddressSupersession;
use App\Models\AddressVerification;
use App\Models\Carrier;
use App\Models\CorrectedAddress;
use App\Services\AddressValidationService;
use App\Services\Invoices\CorrectionGuard;
use App\Services\Invoices\CorrectionThreader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Re-checks one cached good address against ONE carrier's validation API to answer the only question
 * that matters for fee avoidance: "would this carrier accept this address today without a correction
 * fee?" Cache is bypassed (we're testing the carrier, not ourselves). Match → stamp verified; the
 * carrier returns a different form → mark drifted + queue a review event (never auto-thread — an API
 * disagreement is weaker evidence than an actual invoice charge); API error → record the attempt only
 * so a transient outage never un-verifies a good address.
 */
class ReverifyCorrectedAddress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $correctedAddressId, public int $carrierId) {}

    public function handle(AddressValidationService $validation): void
    {
        $good = CorrectedAddress::find($this->correctedAddressId);
        $carrier = Carrier::find($this->carrierId);
        if ($good === null || $carrier === null || $good->isSuperseded()) {
            return;
        }

        $address = Address::create([
            'input_address_1' => $good->address_1,
            'input_address_2' => $good->address_2,
            'input_city' => $good->city,
            'input_state' => $good->state,
            'input_postal' => $good->postal,
            'input_country' => strtoupper($good->country ?? 'US'),
            'validation_status' => 'pending',
            'source' => 'api',
        ]);

        try {
            $result = $validation->useLocalCache(false)->validateAddress($address, $carrier->slug);

            if ($result->output_address_1 === null) {
                $this->stampChecked($good->id, $carrier->id, AddressVerification::STATUS_FAILED);
            } elseif ($this->matches($good, $result)) {
                $this->stampVerified($good->id, $carrier->id);
            } else {
                $this->stampDrifted($good, $carrier, $result);
            }
        } catch (Throwable $e) {
            $this->stampChecked($good->id, $carrier->id, AddressVerification::STATUS_FAILED);
        } finally {
            $address->delete();
        }
    }

    private function matches(CorrectedAddress $good, Address $result): bool
    {
        return CorrectedAddress::normalize($result->output_address_1) === CorrectedAddress::normalize($good->address_1)
            && CorrectedAddress::normalize($result->output_state) === CorrectedAddress::normalize($good->state)
            && $this->zip5($result->output_postal) === $this->zip5($good->postal);
    }

    private function stampVerified(int $addressId, int $carrierId): void
    {
        $v = AddressVerification::firstOrNew(['corrected_address_id' => $addressId, 'carrier_id' => $carrierId]);
        $v->status = AddressVerification::STATUS_VERIFIED;
        $v->verified_at = now();
        $v->checked_at = now();
        $v->source = AddressVerification::SOURCE_API;
        $v->result_snapshot = null;
        $v->save();
    }

    private function stampDrifted(CorrectedAddress $good, Carrier $carrier, Address $result): void
    {
        $form = [
            'address_1' => $result->output_address_1, 'address_2' => $result->output_address_2,
            'city' => $result->output_city, 'state' => $result->output_state,
            'postal' => $result->output_postal, 'postal_ext' => $result->output_postal_ext,
        ];

        $v = AddressVerification::firstOrNew(['corrected_address_id' => $good->id, 'carrier_id' => $carrier->id]);
        $v->status = AddressVerification::STATUS_DRIFTED;
        $v->verified_at = null;
        $v->checked_at = now();
        $v->source = AddressVerification::SOURCE_API;
        $v->result_snapshot = $form;
        $v->save();

        // Surface the drift as an actionable review event (good -> the carrier's preferred form), unless
        // one is already pending. Never auto-applied — a human decides.
        if ($result->output_city === null || $result->output_state === null || $result->output_postal === null) {
            return;
        }
        $newGood = CorrectedAddress::findOrCreateFromCorrection(
            $result->output_address_1, $result->output_address_2, null,
            $result->output_city, $result->output_state, $result->output_postal,
            $result->output_postal_ext, $result->output_country ?? 'us', $carrier->id, null
        )['address'];

        if ($newGood->id === $good->id) {
            return;
        }
        $alreadyQueued = AddressSupersession::query()
            ->where('old_corrected_address_id', $good->id)
            ->where('new_corrected_address_id', $newGood->id)
            ->whereIn('status', [AddressSupersession::STATUS_PENDING_REVIEW, AddressSupersession::STATUS_APPLIED])
            ->exists();
        if ($alreadyQueued) {
            return;
        }

        $verdict = (new CorrectionGuard)->evaluate(
            ['address_1' => $good->address_1, 'city' => $good->city, 'state' => $good->state, 'postal' => $good->postal],
            ['address_1' => $newGood->address_1, 'city' => $newGood->city, 'state' => $newGood->state, 'postal' => $newGood->postal],
        );
        app(CorrectionThreader::class)->recordEvent(
            $good, $newGood, AddressSupersession::TRIGGER_REVERIFY_DRIFT,
            $verdict['verdict'] === CorrectionGuard::REJECT
                ? AddressSupersession::STATUS_REJECTED_GARBAGE
                : AddressSupersession::STATUS_PENDING_REVIEW,
            ['carrier_id' => $carrier->id, 'guard_result' => $verdict]
        );
    }

    private function stampChecked(int $addressId, int $carrierId, string $status): void
    {
        $v = AddressVerification::firstOrNew(['corrected_address_id' => $addressId, 'carrier_id' => $carrierId]);
        if (! $v->exists) {
            $v->status = $status;
        }
        $v->checked_at = now(); // verified_at untouched — a transient failure never un-verifies
        $v->save();
    }

    private function zip5(?string $postal): string
    {
        return substr((string) preg_replace('/[^0-9]/', '', (string) $postal), 0, 5);
    }
}
