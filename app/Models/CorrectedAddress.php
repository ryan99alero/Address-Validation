<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CorrectedAddress extends Model
{
    protected $fillable = [
        'address_1',
        'address_2',
        'address_3',
        'city',
        'state',
        'postal',
        'postal_ext',
        'country',
        'address_hash',
        'first_carrier_id',
        'superseded_by_id',
        'superseded_at',
        'supersede_reason',
        'is_residential',
        'usage_count',
        'variant_count',
        'first_seen_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_residential' => 'boolean',
            'usage_count' => 'integer',
            'variant_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_used_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * Active = a live (non-superseded) good address. Superseded rows are dead forms the engine
     * resolves past.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    // Relationships

    public function firstCarrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'first_carrier_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AddressVariant::class);
    }

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class);
    }

    /**
     * The good address this one was superseded by (null = this is a live/terminal form).
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * The good addresses that were superseded INTO this one.
     */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(AddressVerification::class);
    }

    /**
     * Supersession events where this record is the old (superseded-from) side.
     */
    public function supersessionEvents(): HasMany
    {
        return $this->hasMany(AddressSupersession::class, 'old_corrected_address_id');
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }

    /**
     * Walk the supersession pointer to the live terminal address. Bounded + visited-guarded so a
     * malformed cycle logs and returns the current node rather than looping forever (MySQL cannot
     * enforce acyclicity). Only ever used off the hot path (UI, guards, backfill).
     */
    public function resolveTerminal(int $maxHops = 10): self
    {
        $node = $this;
        $seen = [$node->id => true];

        for ($hop = 0; $hop < $maxHops; $hop++) {
            if ($node->superseded_by_id === null) {
                return $node;
            }
            $next = self::find($node->superseded_by_id);
            if ($next === null || isset($seen[$next->id])) {
                Log::warning('Supersession chain anomaly', [
                    'from' => $this->id, 'stuck_at' => $node->id, 'next' => $node->superseded_by_id,
                ]);

                return $node;
            }
            $seen[$next->id] = true;
            $node = $next;
        }

        Log::warning('Supersession chain exceeded max hops', ['from' => $this->id]);

        return $node;
    }

    /**
     * This node then each successor up to the terminal (for the UI chain view). Never includes a
     * repeat.
     *
     * @return array<int, self>
     */
    public function chainToTerminal(int $maxHops = 10): array
    {
        $chain = [$this];
        $node = $this;
        $seen = [$node->id => true];

        for ($hop = 0; $hop < $maxHops && $node->superseded_by_id !== null; $hop++) {
            $next = self::find($node->superseded_by_id);
            if ($next === null || isset($seen[$next->id])) {
                break;
            }
            $chain[] = $next;
            $seen[$next->id] = true;
            $node = $next;
        }

        return $chain;
    }

    /**
     * How many times each distinct bad address (variant) was actually charged a correction fee on a
     * real carrier invoice — the count of carrier_invoice_lines whose original address hashes to that
     * variant — plus the newest tracking number and a real reference date (ship_date, else the
     * invoice date; never the import date) for that occurrence. This is the honest "Times Corrected"
     * value, as opposed to AddressVariant::$times_seen, which only counts validation-cache lookups.
     * Keyed by the variant's input_hash so a table row can look itself up in O(1).
     *
     * @return array<string, array{count: int, tracking: ?string, date: ?string}>
     */
    public function correctionOccurrencesByHash(): array
    {
        $lines = DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->where('l.corrected_address_id', $this->id)
            // Newest first, so the first line seen per hash is also the most recent one — by a REAL
            // date (shipment date, or invoice date when ship_date is missing/stale).
            ->orderByRaw('COALESCE(l.ship_date, ci.invoice_date) DESC')
            ->orderByDesc('l.id')
            ->get(['l.tracking_number', 'l.ship_date', 'ci.invoice_date',
                'l.original_address_1', 'l.original_city', 'l.original_state', 'l.original_postal', 'l.original_country']);

        $byHash = [];
        foreach ($lines as $line) {
            if (($line->original_address_1 ?? '') === '') {
                continue;
            }
            $hash = AddressVariant::computeHash(
                $line->original_address_1, $line->original_city, $line->original_state,
                (string) $line->original_postal, $line->original_country ?? 'us'
            );

            if (! isset($byHash[$hash])) {
                // First (newest) line for this bad address — capture its tracking + reference date.
                $tracking = ($line->tracking_number ?? '') !== '' ? (string) $line->tracking_number : null;
                $byHash[$hash] = [
                    'count' => 0,
                    'tracking' => $tracking,
                    'date' => $line->ship_date ?: ($line->invoice_date ?: null),
                ];
            }
            $byHash[$hash]['count']++;
        }

        return $byHash;
    }

    /**
     * The date of the most recent time this address was corrected on a real carrier invoice — the
     * shipment date, or the invoice date when the line's ship_date is missing. Null if it has never
     * appeared on an invoice. Unlike last_used_at (a validation-cache timestamp) this is a real date.
     */
    public function latestCorrectionDate(): ?string
    {
        return DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->where('l.corrected_address_id', $this->id)
            ->selectRaw('MAX(COALESCE(l.ship_date, ci.invoice_date)) as d')
            ->value('d');
    }

    /**
     * Per-variant occurrence data for the "Bad Address Variations" table: the invoice-correction
     * count and newest tracking (see correctionOccurrencesByHash()), then CartonCost (Pace job #,
     * customer id) and ChargebackPush (customer name, CSR, salesperson) enrich it by tracking.
     * Keyed by the variant's input_hash so the table can look up each row in O(1).
     *
     * @return array<string, array{count: int, tracking: ?string, date: ?string, job: ?string, customer_id: ?string, customer_name: ?string, csr: ?string, salesperson: ?string}>
     */
    public function variantOccurrences(): array
    {
        $byHash = $this->correctionOccurrencesByHash();

        if ($byHash === []) {
            return [];
        }

        $trackings = array_values(array_unique(array_filter(array_column($byHash, 'tracking'))));
        $cartons = $trackings === [] ? collect() : CartonCost::whereIn('tracking_number', $trackings)->get()->keyBy('tracking_number');
        $pushes = $trackings === [] ? collect() : ChargebackPush::whereIn('tracking_number', $trackings)
            ->orderByDesc('id')->get()->unique('tracking_number')->keyBy('tracking_number');

        $out = [];
        foreach ($byHash as $hash => $occurrence) {
            $tracking = $occurrence['tracking'];
            $carton = $tracking !== null ? $cartons->get($tracking) : null;
            $push = $tracking !== null ? $pushes->get($tracking) : null;
            $out[$hash] = [
                'count' => $occurrence['count'],
                'tracking' => $tracking,
                'date' => $occurrence['date'],
                'job' => $carton?->pace_job_number ?? $push?->pace_job ?? null,
                'customer_id' => $carton?->pace_customer_id ?? $push?->pace_customer_id ?? null,
                'customer_name' => $carton?->pace_customer_name ?? $push?->pace_customer_name ?? null,
                'csr' => $carton?->pace_csr_name ?? $push?->pace_csr_name ?? null,
                'salesperson' => $carton?->pace_salesperson_name ?? $push?->pace_salesperson_name ?? null,
            ];
        }

        return $out;
    }

    // Static Methods

    /**
     * Normalize an address component to lowercase, trimmed, standardized.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = mb_strtolower(trim($value));

        // Standardize common abbreviations
        $replacements = [
            ' street' => ' st',
            ' avenue' => ' ave',
            ' boulevard' => ' blvd',
            ' drive' => ' dr',
            ' lane' => ' ln',
            ' road' => ' rd',
            ' court' => ' ct',
            ' place' => ' pl',
            ' circle' => ' cir',
            ' highway' => ' hwy',
            ' parkway' => ' pkwy',
            ' suite' => ' ste',
            ' apartment' => ' apt',
            ' building' => ' bldg',
            ' floor' => ' fl',
            ' north' => ' n',
            ' south' => ' s',
            ' east' => ' e',
            ' west' => ' w',
            ' northeast' => ' ne',
            ' northwest' => ' nw',
            ' southeast' => ' se',
            ' southwest' => ' sw',
        ];

        $value = str_replace(array_keys($replacements), array_values($replacements), $value);

        // Remove extra whitespace
        $value = preg_replace('/\s+/', ' ', $value);

        // Remove common punctuation that doesn't affect matching
        $value = str_replace(['.', ',', '#'], '', $value);

        return trim($value);
    }

    /**
     * Normalize a postal code to standard format.
     * Handles malformed data like "67215120720" by extracting just the ZIP.
     */
    public static function normalizePostal(?string $postal): string
    {
        if ($postal === null || $postal === '') {
            return '';
        }

        $postal = trim($postal);

        // Remove any non-alphanumeric characters except hyphen
        $postal = preg_replace('/[^a-zA-Z0-9\-]/', '', $postal);

        // For US ZIP codes (all digits)
        if (preg_match('/^(\d{5})(\d{4})?/', $postal, $matches)) {
            // Standard 5-digit ZIP, optionally with 4-digit extension
            return $matches[1].(isset($matches[2]) ? '-'.$matches[2] : '');
        }

        // For Canadian postal codes (A1A 1A1 format)
        if (preg_match('/^([A-Za-z]\d[A-Za-z])\s*(\d[A-Za-z]\d)?/', $postal, $matches)) {
            return strtoupper($matches[1].(isset($matches[2]) ? ' '.$matches[2] : ''));
        }

        // Truncate if still too long (max 10 chars for safety)
        if (strlen($postal) > 10) {
            $postal = substr($postal, 0, 10);
        }

        return strtolower($postal);
    }

    /**
     * Compute hash for a corrected address.
     */
    public static function computeHash(
        string $address1,
        ?string $city,
        ?string $state,
        ?string $postal,
        ?string $country = 'us'
    ): string {
        $normalized = implode('|', [
            self::normalize($address1),
            self::normalize($city),
            self::normalize($state),
            self::normalizePostal($postal),
            self::normalize($country ?? 'us'),
        ]);

        return hash('sha256', $normalized);
    }

    /**
     * Find or create a corrected address record.
     *
     * @return array{address: CorrectedAddress, created: bool}
     */
    public static function findOrCreateFromCorrection(
        string $address1,
        ?string $address2,
        ?string $address3,
        string $city,
        string $state,
        string $postal,
        ?string $postalExt = null,
        string $country = 'us',
        ?int $carrierId = null,
        ?bool $isResidential = null
    ): array {
        $hash = self::computeHash($address1, $city, $state, $postal, $country);

        $existing = self::where('address_hash', $hash)->first();

        if ($existing) {
            $existing->increment('usage_count');
            $existing->update(['last_used_at' => now()]);

            return ['address' => $existing, 'created' => false];
        }

        $address = self::create([
            'address_1' => self::normalize($address1),
            'address_2' => $address2 ? self::normalize($address2) : null,
            'address_3' => $address3 ? self::normalize($address3) : null,
            'city' => self::normalize($city),
            'state' => self::normalize($state),
            'postal' => self::normalizePostal($postal),
            'postal_ext' => $postalExt ? self::normalizePostal($postalExt) : null,
            'country' => self::normalize($country),
            'address_hash' => $hash,
            'first_carrier_id' => $carrierId,
            'is_residential' => $isResidential,
            'usage_count' => 1,
            'variant_count' => 0,
            'first_seen_at' => now(),
            'last_used_at' => now(),
        ]);

        return ['address' => $address, 'created' => true];
    }

    // Accessors

    /**
     * Get the full address as a single line.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_1,
            $this->address_2,
            $this->address_3,
            $this->city,
            $this->state,
            $this->postal.($this->postal_ext ? '-'.$this->postal_ext : ''),
        ]);

        return implode(', ', $parts);
    }
}
