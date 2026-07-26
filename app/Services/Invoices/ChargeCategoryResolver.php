<?php

namespace App\Services\Invoices;

use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use Illuminate\Support\Collection;

/**
 * Normalizes a carrier's raw charge code/description into a canonical charge_category_id, and
 * records WHICH crosswalk row decided it (charge_type_id).
 *
 * Precedence (highest first):
 *   1. Correction-prefix rules (address vs shipping-charge corrections — semantic re-rating logic).
 *   2. The operator-editable crosswalk (carrier_charge_types): exact label per format, then
 *      prefix/contains. Carrier-specific beats generic.
 *   3. Legacy charge_code_mappings fallback (description substring / exact code).
 */
class ChargeCategoryResolver
{
    /** @var Collection<int, ChargeCodeMapping>|null */
    private ?Collection $mappings = null;

    private bool $chargeTypesLoaded = false;

    /**
     * Exact-match crosswalk lookup: [format 'csv'|'pdf'][carrierId|0][normalized label] => rows.
     *
     * @var array<string, array<int, array<string, list<CarrierChargeType>>>>
     */
    private array $exactTypeMap = [];

    /**
     * Prefix/contains crosswalk rows, priority- then carrier-specificity-sorted.
     *
     * @var list<CarrierChargeType>
     */
    private array $fuzzyTypeList = [];

    /**
     * Memoized [categoryId, chargeTypeId] keyed by carrier|sourceType|code|description. A large
     * batch invoice resolves the same handful of descriptions thousands of times; caching turns
     * that from O(charges × rules) into O(distinct descriptions × rules).
     *
     * @var array<string, array{0: ?int, 1: ?int}>
     */
    private array $cache = [];

    public function resolve(?int $carrierId, ?string $code, ?string $description, ?string $sourceType = null): ?int
    {
        return $this->resolveDetailed($carrierId, $code, $description, $sourceType)[0];
    }

    /**
     * Resolve a raw charge to [charge_category_id, charge_type_id]. The category is WHAT the charge
     * is; the charge_type_id records WHICH crosswalk row decided it (null when a correction-prefix
     * rule or a legacy mapping decided, or when nothing matched). Passing $sourceType ('csv'|'pdf')
     * lets the crosswalk match the right per-format label.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function resolveDetailed(?int $carrierId, ?string $code, ?string $description, ?string $sourceType = null): array
    {
        $code = $code !== null ? trim($code) : null;
        $description = $description !== null ? trim($description) : null;

        $cacheKey = ($carrierId ?? 'n').'|'.((string) $sourceType).'|'.((string) $code).'|'.((string) $description);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // A correction line prefixes the correction onto the description. An ADDRESS correction is a
        // flat fee, so its line IS the fee (→ Address Correction) even when labelled with a service
        // ("Address Correction Ground" is a fixed $20.20, not the ~$8 real transport). A SHIPPING
        // CHARGE correction is a real re-rate, so its line keeps the underlying transport/fuel
        // category. In both, a named non-base surcharge (fuel) keeps its own category. Decided ahead
        // of the crosswalk — this is semantic re-rating logic, not a per-label mapping.
        if ($description !== null && $description !== '') {
            foreach (self::CORRECTION_PREFIXES as $prefix => [$feeCategory, $transportIsFee]) {
                if (stripos($description, $prefix) === 0) {
                    $cat = $this->correctionCategory(
                        $carrierId, $feeCategory, $transportIsFee, trim(substr($description, strlen($prefix))), $sourceType
                    );

                    return $this->cache[$cacheKey] = [$cat, null];
                }
            }
        }

        if (($hit = $this->matchChargeType($carrierId, $code, $description, $sourceType)) !== null) {
            return $this->cache[$cacheKey] = $hit;
        }

        foreach ($this->sortedMappings() as $mapping) {
            if ($mapping->carrier_id !== null && $mapping->carrier_id !== $carrierId) {
                continue;
            }

            if ($mapping->match_type === ChargeCodeMapping::MATCH_CODE) {
                if ($code !== null && $code !== '' && strcasecmp($code, $mapping->match_value) === 0) {
                    return $this->cache[$cacheKey] = [$mapping->charge_category_id, null];
                }
            } elseif ($description !== null && $description !== '' && stripos($description, $mapping->match_value) !== false) {
                return $this->cache[$cacheKey] = [$mapping->charge_category_id, null];
            }
        }

        return $this->cache[$cacheKey] = [null, null];
    }

    /**
     * Match a charge against the operator crosswalk: exact label first (carrier-specific beats
     * generic; when a source type is known only that format's label is considered), then
     * prefix/contains rows. A hit returns [charge_category_id, charge_type_id]; the category may be
     * null ("needs review") while the type still records the charge's identity.
     *
     * @return array{0: ?int, 1: ?int}|null
     */
    private function matchChargeType(?int $carrierId, ?string $code, ?string $description, ?string $sourceType): ?array
    {
        if ($description === null || $description === '') {
            return null;
        }

        $this->loadChargeTypes();

        $norm = mb_strtolower($description);
        $formats = match ($sourceType) {
            'csv' => ['csv'],
            'pdf' => ['pdf'],
            default => ['csv', 'pdf'],
        };
        $carrierKeys = $carrierId !== null ? [$carrierId, 0] : [0];

        foreach ($carrierKeys as $ck) {
            foreach ($formats as $format) {
                foreach ($this->exactTypeMap[$format][$ck][$norm] ?? [] as $row) {
                    // An optional CSV section-code qualifier (ISS/SCC…) narrows a shared label; when
                    // set it must also match, otherwise this candidate is skipped.
                    if ($format === 'csv' && $row->csv_code !== null && $row->csv_code !== ''
                        && ($code === null || strcasecmp($code, $row->csv_code) !== 0)) {
                        continue;
                    }

                    return [$row->charge_category_id, $row->id];
                }
            }
        }

        foreach ($this->fuzzyTypeList as $row) {
            if ($row->carrier_id !== null && $row->carrier_id !== $carrierId) {
                continue;
            }
            foreach ($formats as $format) {
                $label = $format === 'csv' ? $row->csv_label : $row->pdf_label;
                if ($label === null || $label === '') {
                    continue;
                }
                $needle = mb_strtolower(trim($label));
                $matches = $row->match_style === CarrierChargeType::MATCH_PREFIX
                    ? str_starts_with($norm, $needle)
                    : str_contains($norm, $needle);
                if ($matches) {
                    return [$row->charge_category_id, $row->id];
                }
            }
        }

        return null;
    }

    private function loadChargeTypes(): void
    {
        if ($this->chargeTypesLoaded) {
            return;
        }
        $this->chargeTypesLoaded = true;

        $rows = CarrierChargeType::query()
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn (CarrierChargeType $r): array => [$r->priority, $r->carrier_id !== null ? 1 : 0])
            ->values();

        foreach ($rows as $row) {
            if ($row->match_style !== CarrierChargeType::MATCH_EXACT) {
                $this->fuzzyTypeList[] = $row;

                continue;
            }
            $ck = $row->carrier_id ?? 0;
            if ($row->csv_label !== null && $row->csv_label !== '') {
                $this->exactTypeMap['csv'][$ck][mb_strtolower(trim($row->csv_label))][] = $row;
            }
            if ($row->pdf_label !== null && $row->pdf_label !== '') {
                $this->exactTypeMap['pdf'][$ck][mb_strtolower(trim($row->pdf_label))][] = $row;
            }
        }
    }

    /**
     * Correction prefixes → [fee category name, is a service-labelled line the FEE itself?].
     * Address correction = a flat fee (the transport-labelled line IS the fee). Shipping charge
     * correction = a re-rate (the transport-labelled line keeps its transport category).
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const CORRECTION_PREFIXES = [
        'Address Correction' => ['Address Correction', true],
        'Shipping Charge Correction' => ['Audit / Correction Fee', false],
    ];

    /** @var array<string, ?int> */
    private array $categoryIds = [];

    private function correctionCategory(?int $carrierId, string $feeCategory, bool $transportIsFee, string $remainder, ?string $sourceType): ?int
    {
        if ($remainder === '') {
            return $this->categoryIdByName($feeCategory);
        }

        $remainderCategory = $this->resolve($carrierId, null, $remainder, $sourceType);

        // A named non-base surcharge (fuel, DAS, residential…) keeps its own category.
        if ($remainderCategory !== null && $remainderCategory !== $this->categoryIdByName('Base Transportation')) {
            return $remainderCategory;
        }

        // Remainder is base transport (or unmatched): a flat-fee correction → the fee category;
        // a true re-rate → the underlying transport category as resolved.
        return $transportIsFee ? $this->categoryIdByName($feeCategory) : $remainderCategory;
    }

    private function categoryIdByName(string $name): ?int
    {
        return $this->categoryIds[$name] ??= ChargeCategory::query()->where('name', $name)->value('id');
    }

    /**
     * @return Collection<int, ChargeCodeMapping>
     */
    private function sortedMappings(): Collection
    {
        return $this->mappings ??= ChargeCodeMapping::query()
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn (ChargeCodeMapping $m): array => [
                $m->priority,
                $m->carrier_id !== null ? 1 : 0,
                $m->match_type === ChargeCodeMapping::MATCH_CODE ? 1 : 0,
            ])
            ->values();
    }
}
