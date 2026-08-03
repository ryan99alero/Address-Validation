<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AddressSupersession extends Model
{
    public const TRIGGER_RECORRECTION = 'recorrection';

    public const TRIGGER_VARIANT_CONFLICT = 'variant_conflict';

    public const TRIGGER_REVERIFY_DRIFT = 'reverify_drift';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_BACKFILL = 'backfill';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED_GARBAGE = 'rejected_garbage';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'old_corrected_address_id',
        'new_corrected_address_id',
        'old_snapshot',
        'new_snapshot',
        'carrier_id',
        'carrier_invoice_line_id',
        'trigger',
        'status',
        'guard_result',
        'search_text',
        'reference_date',
        'corrected_override',
        'corrected_edited_at',
        'corrected_edited_by',
        'detected_at',
        'applied_at',
        'applied_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_snapshot' => 'array',
            'new_snapshot' => 'array',
            'guard_result' => 'array',
            'corrected_override' => 'array',
            'reference_date' => 'date',
            'corrected_edited_at' => 'datetime',
            'detected_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function oldAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class, 'old_corrected_address_id');
    }

    public function newAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class, 'new_corrected_address_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoiceLine::class, 'carrier_invoice_line_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function correctedEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_edited_by');
    }

    public function isManuallyEdited(): bool
    {
        return $this->corrected_edited_at !== null;
    }

    /**
     * The recipient's company + contact for this shipment. Carriers correct only the address, so the
     * name/company is the same on both sides — resolved from the linked invoice line, else the newest
     * line under either corrected address.
     *
     * @return array{company: ?string, name: ?string}
     */
    public function contact(): array
    {
        $line = $this->carrier_invoice_line_id ? CarrierInvoiceLine::find($this->carrier_invoice_line_id) : null;

        if ($line === null) {
            $ids = array_values(array_filter([$this->new_corrected_address_id, $this->old_corrected_address_id]));
            if ($ids !== []) {
                $line = CarrierInvoiceLine::whereIn('corrected_address_id', $ids)
                    ->where(fn ($q) => $q->whereNotNull('original_company')->orWhereNotNull('original_name'))
                    ->orderByDesc('ship_date')->first();
            }
        }

        return ['company' => $line?->original_company ?: null, 'name' => $line?->original_name ?: null];
    }

    /**
     * The "Was" address as labeled fields (company/contact from the recipient, address from the old
     * snapshot).
     *
     * @return array{company: ?string, name: ?string, address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string, postal_ext: ?string}
     */
    public function wasFields(): array
    {
        return $this->fieldsFrom($this->old_snapshot ?? [], $this->contact());
    }

    /**
     * The "Corrected to" address as labeled fields — the human override when present, else the
     * carrier's corrected snapshot (with the recipient's company/contact).
     *
     * @return array{company: ?string, name: ?string, address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string, postal_ext: ?string}
     */
    public function correctedFields(): array
    {
        if (is_array($this->corrected_override) && ($this->corrected_override['address_1'] ?? '') !== '') {
            return $this->fieldsFrom($this->corrected_override, [
                'company' => $this->corrected_override['company'] ?? null,
                'name' => $this->corrected_override['name'] ?? null,
            ]);
        }

        return $this->fieldsFrom($this->new_snapshot ?? [], $this->contact());
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  array{company: ?string, name: ?string}  $contact
     * @return array{company: ?string, name: ?string, address_1: ?string, address_2: ?string, city: ?string, state: ?string, postal: ?string, postal_ext: ?string}
     */
    private function fieldsFrom(array $snap, array $contact): array
    {
        return [
            'company' => $contact['company'],
            'name' => $contact['name'],
            'address_1' => $snap['address_1'] ?? null,
            'address_2' => $snap['address_2'] ?? null,
            'city' => $snap['city'] ?? null,
            'state' => $snap['state'] ?? null,
            'postal' => $snap['postal'] ?? null,
            'postal_ext' => $snap['postal_ext'] ?? null,
        ];
    }

    /**
     * Rebuild the denormalized search haystack for this event: both corrections' addresses (original
     * and corrected), tracking numbers, invoice numbers, and Pace job/customer — so the Re-Corrections
     * search box matches any of them. Correction 2 (this event, B->C) comes from the linked invoice
     * line or is reconstructed; Correction 1 (something->B) is the newest line that resolved to B.
     */
    public function rebuildSearchText(): void
    {
        $parts = [];

        foreach ([$this->old_snapshot, $this->new_snapshot, $this->corrected_override] as $snap) {
            if (is_array($snap)) {
                $parts[] = implode(' ', array_filter([$snap['company'] ?? null, $snap['name'] ?? null, $snap['address_1'] ?? null, $snap['city'] ?? null, $snap['state'] ?? null, $snap['postal'] ?? null]));
            }
        }

        $contact = $this->contact();
        $parts[] = $contact['company'];
        $parts[] = $contact['name'];

        $old = $this->old_corrected_address_id ? CorrectedAddress::find($this->old_corrected_address_id) : null;
        $new = $this->new_corrected_address_id ? CorrectedAddress::find($this->new_corrected_address_id) : null;

        // Correction 2 evidence (B -> C): the linked line, else the line under C whose original is B.
        $c2 = $this->carrier_invoice_line_id ? CarrierInvoiceLine::find($this->carrier_invoice_line_id) : null;
        if ($c2 === null && $old !== null && $new !== null) {
            foreach (CarrierInvoiceLine::where('corrected_address_id', $new->id)->whereNotNull('tracking_number')->where('tracking_number', '<>', '')->limit(300)->get() as $cand) {
                if (($cand->original_address_1 ?? '') === '') {
                    continue;
                }
                if (CorrectedAddress::computeHash($cand->original_address_1, $cand->original_city, $cand->original_state, (string) $cand->original_postal, $cand->original_country ?? 'us') === $old->address_hash) {
                    $c2 = $cand;
                    break;
                }
            }
        }

        // Correction 1 evidence (something -> B): the newest line that resolved to B.
        $c1 = $old ? CarrierInvoiceLine::where('corrected_address_id', $old->id)->whereNotNull('tracking_number')->where('tracking_number', '<>', '')->orderByDesc('ship_date')->first() : null;

        foreach ([$c1, $c2] as $line) {
            if ($line === null) {
                continue;
            }
            $parts[] = $line->tracking_number;
            $parts[] = $line->original_address_1;
            $parts[] = optional(CarrierInvoice::find($line->carrier_invoice_id))->invoice_number;
            if ($carton = CartonCost::where('tracking_number', $line->tracking_number)->first()) {
                $parts[] = $carton->pace_job_number;
                $parts[] = $carton->pace_customer_name;
                $parts[] = $carton->pace_customer_id;
            }
            if ($push = ChargebackPush::where('tracking_number', $line->tracking_number)->first()) {
                $parts[] = $push->pace_job;
                $parts[] = $push->pace_customer_name;
            }
        }

        // Real business date: the re-correction's (C2) shipment date, else its invoice date; fall back
        // to correction 1. Never detected_at (the processing timestamp).
        $refLine = $c2 ?? $c1;
        if ($refLine !== null) {
            $refDate = $refLine->ship_date ?: optional(CarrierInvoice::find($refLine->carrier_invoice_id))->invoice_date;
            $this->reference_date = $refDate ? Carbon::parse($refDate)->toDateString() : null;
        }

        $this->search_text = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter($parts)))));
        $this->saveQuietly();
    }
}
