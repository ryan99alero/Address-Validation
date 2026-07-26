<?php

namespace App\Models;

use App\Jobs\RecategorizeChargesJob;
use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One per-carrier charge type in the operator-editable crosswalk: the carrier's own name for a
 * charge (identified by its CSV header label and/or PDF line label, optionally qualified by a UPS
 * CSV section code) mapped to one of our universal charge categories. A null charge_category_id
 * means "seen but not yet categorized" — the operator's review worklist. Consulted by
 * {@see ChargeCategoryResolver} ahead of the legacy charge_code_mappings.
 */
class CarrierChargeType extends Model
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_PREFIX = 'prefix';

    public const MATCH_CONTAINS = 'contains';

    protected $fillable = [
        'carrier_id',
        'display_name',
        'csv_label',
        'csv_code',
        'pdf_label',
        'match_style',
        'charge_category_id',
        'priority',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChargeCategory::class, 'charge_category_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(CarrierCharge::class, 'charge_type_id');
    }

    /**
     * Map a carrier charge (identified by its label + the format it appeared in) to a category:
     * update the existing crosswalk row for that carrier+label+format if one exists, else create it,
     * then re-apply to existing charges. Backs the catalog's "Map this charge" action.
     */
    public static function mapCharge(?int $carrierId, string $label, bool $isPdf, string $displayName, ?int $categoryId): self
    {
        $type = static::query()
            ->where('carrier_id', $carrierId)
            ->when($isPdf,
                fn ($q) => $q->where('pdf_label', $label),
                fn ($q) => $q->where('csv_label', $label),
            )
            ->first();

        if ($type) {
            $type->update([
                'display_name' => $displayName !== '' ? $displayName : $type->display_name,
                'charge_category_id' => $categoryId,
            ]);
        } else {
            $type = static::create([
                'carrier_id' => $carrierId,
                'display_name' => $displayName !== '' ? $displayName : $label,
                'csv_label' => $isPdf ? null : $label,
                'pdf_label' => $isPdf ? $label : null,
                'match_style' => self::MATCH_EXACT,
                'charge_category_id' => $categoryId,
                'priority' => 100,
                'is_active' => true,
            ]);
        }

        $type->recategorizeAffectedCharges();

        return $type;
    }

    /**
     * Re-run category resolution over the existing charges this crosswalk row governs, so an edit to
     * its category/labels takes effect on already-imported data. Dispatched to the queue, scoped to
     * this carrier and the row's labels, so it stays cheap.
     */
    public function recategorizeAffectedCharges(): void
    {
        $descriptions = array_values(array_unique(array_filter([
            $this->csv_label,
            $this->pdf_label,
            $this->display_name,
        ])));

        if ($descriptions === []) {
            return;
        }

        RecategorizeChargesJob::dispatch($this->carrier_id, $descriptions);
    }
}
