<?php

namespace App\Models;

use App\Observers\CarrierInvoiceLineObserver;
use App\Services\Invoices\CorrectionGuard;
use App\Services\Invoices\CorrectionThreader;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CarrierInvoiceLineObserver::class])]
class CarrierInvoiceLine extends Model
{
    protected $fillable = [
        'carrier_invoice_id',
        'tracking_number',
        'ship_date',
        'delivery_date',
        'original_name',
        'original_company',
        'original_address_1',
        'original_address_2',
        'original_address_3',
        'original_city',
        'original_state',
        'original_postal',
        'original_country',
        'corrected_address_1',
        'corrected_address_2',
        'corrected_address_3',
        'corrected_city',
        'corrected_state',
        'corrected_postal',
        'corrected_country',
        'charge_code',
        'charge_description',
        'charge_amount',
        'severity_score',
        'severity_category',
        'change_type',
        'corrected_address_id',
        'shipping_lookup_status',
        'shipping_lookup_at',
        'billed_to_pace',
        'billed_at',
        'pace_job_number',
        'pace_customer_id',
    ];

    public const LOOKUP_STATUS_FOUND = 'found';

    public const LOOKUP_STATUS_NOT_FOUND = 'not_found';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'delivery_date' => 'date',
            'charge_amount' => 'decimal:2',
            'severity_score' => 'integer',
            'billed_to_pace' => 'boolean',
            'billed_at' => 'datetime',
            'shipping_lookup_at' => 'datetime',
        ];
    }

    // Relationships

    public function carrierInvoice(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoice::class);
    }

    public function correctedAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class);
    }

    // Scopes

    public function scopeUnbilled($query)
    {
        return $query->where('billed_to_pace', false);
    }

    public function scopeBilled($query)
    {
        return $query->where('billed_to_pace', true);
    }

    public function scopeWithCorrections($query)
    {
        return $query->whereNotNull('corrected_address_1');
    }

    public function scopeNeedsShippingLookup($query)
    {
        return $query->whereNull('original_address_1')
            ->whereNull('shipping_lookup_status')
            ->whereNotNull('tracking_number');
    }

    public function scopeShippingLookupFound($query)
    {
        return $query->where('shipping_lookup_status', self::LOOKUP_STATUS_FOUND);
    }

    public function scopeShippingLookupNotFound($query)
    {
        return $query->where('shipping_lookup_status', self::LOOKUP_STATUS_NOT_FOUND);
    }

    // Methods

    /**
     * Check if this line has an address correction.
     */
    public function hasCorrection(): bool
    {
        return $this->corrected_address_1 !== null;
    }

    /**
     * Mark as billed to Pace.
     */
    public function markBilled(?string $jobNumber = null, ?string $customerId = null): void
    {
        $this->update([
            'billed_to_pace' => true,
            'billed_at' => now(),
            'pace_job_number' => $jobNumber,
            'pace_customer_id' => $customerId,
        ]);
    }

    /**
     * Link this line to the address correction cache.
     * Returns true if this created a NEW variant mapping (not a duplicate).
     */
    public function linkToCorrectionCache(): bool
    {
        if (! $this->hasCorrection()) {
            return false;
        }

        // findOrCreateFromCorrection needs a complete corrected address (non-null city/state/
        // postal). Some carrier corrections parse without those; skip caching them — the line
        // keeps its raw data — rather than throwing and failing the whole invoice file's import.
        if ($this->corrected_city === null || $this->corrected_state === null || $this->corrected_postal === null) {
            return false;
        }

        // Find or create the corrected address
        $result = CorrectedAddress::findOrCreateFromCorrection(
            $this->corrected_address_1,
            $this->corrected_address_2,
            $this->corrected_address_3,
            $this->corrected_city,
            $this->corrected_state,
            $this->corrected_postal,
            null,
            $this->corrected_country ?? 'us',
            $this->carrierInvoice?->carrier_id,
            null
        );

        $correctedAddress = $result['address'];
        $isNewVariant = false;

        $teachVariant = $this->original_address_1 && $this->original_postal && ! $this->originalIsOwnAddress();

        // Phase 3 — detect a re-correction as we ingest it and thread it (or queue it for review /
        // reject garbage) so the cache converges instead of fragmenting. Returns the good address the
        // variant should bind to, or null when the correction is garbage and must not be taught.
        if ($teachVariant && config('correction_cache.ingest_threading', true)) {
            $target = $this->applyIngestThreading($correctedAddress);
            if ($target === null) {
                $this->update(['corrected_address_id' => $correctedAddress->id]);

                return false;
            }
            $correctedAddress = $target;
        }

        // Create variant mapping for the original (bad) address — but NOT when the
        // "original" is our own address. Carriers sometimes encode the shipper (RAND)
        // as the original recipient on returns/undeliverables; the invoice line keeps
        // that factual data, but teaching the validation cache to "correct" our own
        // address to a customer's would poison every future lookup of our address.
        if ($teachVariant) {
            $variantResult = AddressVariant::createOrUpdateVariant(
                $correctedAddress->id,
                $this->original_address_1,
                $this->original_address_2,
                $this->original_city,
                $this->original_state,
                $this->original_postal,
                $this->original_country ?? 'us'
            );
            $isNewVariant = $variantResult['created'] ?? false;
        }

        // Link this invoice line to the corrected address
        $this->update(['corrected_address_id' => $correctedAddress->id]);

        return $isNewVariant;
    }

    /**
     * Phase 3 ingest-time threading. The corrected (good) address for this line is $corrected; the
     * bad address we shipped to is original_*. Detects the two re-correction shapes and threads /
     * reviews / rejects via the shared guard + threader:
     *   - garbage: the carrier "corrected" us to a different place / our own dock — refuse it
     *   - T1: original is itself a good address we already hold — it was re-corrected to $corrected
     *   - T2: original already resolves to a DIFFERENT good than $corrected — fragmentation drift
     * Returns the good address the variant + line should bind to (the live terminal), or null when
     * the correction is garbage and must not be taught to the cache.
     */
    private function applyIngestThreading(CorrectedAddress $corrected): ?CorrectedAddress
    {
        $terminal = $corrected->resolveTerminal();
        $guard = new CorrectionGuard;
        $threader = app(CorrectionThreader::class);
        $carrierId = $this->carrierInvoice?->carrier_id;
        $date = $this->ship_date ?? $this->carrierInvoice?->invoice_date;

        $originalForm = [
            'address_1' => $this->original_address_1, 'city' => $this->original_city,
            'state' => $this->original_state, 'postal' => $this->original_postal,
        ];

        // Garbage: don't teach it — the carrier corrected our shipment to somewhere it doesn't belong.
        $verdict = $guard->evaluate($originalForm, $this->addressForm($terminal));
        if ($verdict['verdict'] === CorrectionGuard::REJECT) {
            $threader->recordEvent(null, $terminal, AddressSupersession::TRIGGER_RECORRECTION,
                AddressSupersession::STATUS_REJECTED_GARBAGE,
                ['carrier_id' => $carrierId, 'carrier_invoice_line_id' => $this->id, 'guard_result' => $verdict]);

            return null;
        }

        $originalHash = CorrectedAddress::computeHash(
            $this->original_address_1, $this->original_city, $this->original_state,
            $this->original_postal, $this->original_country ?? 'us'
        );

        // T1: original is a good address we already hold, now re-corrected to $terminal.
        $heldGood = CorrectedAddress::query()->where('address_hash', $originalHash)->whereNull('superseded_by_id')->first();
        if ($heldGood !== null && $heldGood->id !== $terminal->id) {
            $this->guardedThread($heldGood, $terminal, AddressSupersession::TRIGGER_RECORRECTION, $guard, $threader, $carrierId, $date);

            return $terminal->fresh();
        }

        // T2: the same bad input already resolves to a different good than $terminal (fragmentation).
        $existing = AddressVariant::query()
            ->where('input_postal', CorrectedAddress::normalizePostal($this->original_postal))
            ->where('input_hash', $originalHash)
            ->first();
        if ($existing !== null) {
            $currentTerminal = CorrectedAddress::find($existing->corrected_address_id)?->resolveTerminal();
            if ($currentTerminal !== null && $currentTerminal->id !== $terminal->id) {
                $this->guardedThread($currentTerminal, $terminal, AddressSupersession::TRIGGER_VARIANT_CONFLICT, $guard, $threader, $carrierId, $date);
            }
        }

        return $terminal->fresh();
    }

    private function guardedThread(CorrectedAddress $from, CorrectedAddress $to, string $trigger, CorrectionGuard $guard, CorrectionThreader $threader, ?int $carrierId, mixed $date): void
    {
        $verdict = $guard->evaluate($this->addressForm($from), $this->addressForm($to));
        $evidence = [
            'trigger' => $trigger, 'carrier_id' => $carrierId, 'carrier_invoice_line_id' => $this->id,
            'date' => $date, 'guard_result' => $verdict,
        ];

        match ($verdict['verdict']) {
            CorrectionGuard::APPLY => $threader->thread($from, $to, $evidence),
            CorrectionGuard::REJECT => $threader->recordEvent($from, $to, $trigger, AddressSupersession::STATUS_REJECTED_GARBAGE, $evidence),
            default => $threader->recordEvent($from, $to, $trigger, AddressSupersession::STATUS_PENDING_REVIEW, $evidence),
        };
    }

    /**
     * @return array{address_1: ?string, city: ?string, state: ?string, postal: ?string}
     */
    private function addressForm(CorrectedAddress $a): array
    {
        return ['address_1' => $a->address_1, 'city' => $a->city, 'state' => $a->state, 'postal' => $a->postal];
    }

    /**
     * True when this line's ORIGINAL address is our own company origin address — used to
     * keep our address out of the validation cache as a "bad" variant. Matches on the
     * street "core" (house number + primary name, with directionals and suffixes stripped)
     * + 5-digit ZIP, so "2820 South Hoover" and "2820 S. Hoover Rd" resolve to the same
     * core "2820 HOOVER".
     */
    public function originalIsOwnAddress(): bool
    {
        $company = CompanySetting::instance();
        if (empty($company->address_line_1) || empty($company->postal_code)) {
            return false;
        }

        $zip5 = fn (?string $s): string => substr((string) preg_replace('/[^0-9]/', '', (string) $s), 0, 5);

        $own = self::streetCore($company->address_line_1);
        $line = self::streetCore($this->original_address_1);
        if ($own === '' || $line === '') {
            return false;
        }

        return $own === $line && $zip5($this->original_postal) === $zip5($company->postal_code);
    }

    /**
     * Reduce a street line to a comparable core: uppercase, drop punctuation, and remove
     * directional (N/S/E/W/NORTH/…) and street-suffix (RD/ROAD/ST/AVE/…) tokens, leaving
     * just the house number + primary name (e.g. "2820 S. Hoover Rd" -> "2820HOOVER").
     */
    public static function streetCore(?string $street): string
    {
        $noise = ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW', 'NORTH', 'SOUTH', 'EAST', 'WEST',
            'RD', 'ROAD', 'ST', 'STREET', 'AVE', 'AVENUE', 'BLVD', 'BOULEVARD', 'DR', 'DRIVE',
            'LN', 'LANE', 'CT', 'COURT', 'PL', 'PLACE', 'CIR', 'CIRCLE', 'HWY', 'HIGHWAY',
            'PKWY', 'PARKWAY', 'WAY', 'TER', 'TERRACE', 'PLZ', 'PLAZA', 'SQ', 'LOOP', 'TRL', 'TRAIL'];

        $tokens = preg_split('/\s+/', trim(strtoupper((string) preg_replace('/[^A-Za-z0-9\s]/', ' ', (string) $street))));

        return implode('', array_filter($tokens, fn (string $t): bool => $t !== '' && ! in_array($t, $noise, true)));
    }

    // Accessors

    public function getOriginalFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->original_address_1,
            $this->original_address_2,
            $this->original_city,
            $this->original_state,
            $this->original_postal,
        ]);

        return implode(', ', $parts);
    }

    public function getCorrectedFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->corrected_address_1,
            $this->corrected_address_2,
            $this->corrected_city,
            $this->corrected_state,
            $this->corrected_postal,
        ]);

        return implode(', ', $parts);
    }
}
