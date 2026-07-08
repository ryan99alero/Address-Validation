<?php

namespace App\Services\Invoices;

use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use Illuminate\Support\Collection;

/**
 * Normalizes a carrier's raw charge code/description into a canonical
 * charge_category_id using the charge_code_mappings rules.
 *
 * Precedence (highest first): explicit priority, carrier-specific over generic,
 * exact code match over description substring.
 */
class ChargeCategoryResolver
{
    /** @var Collection<int, ChargeCodeMapping>|null */
    private ?Collection $mappings = null;

    /**
     * Memoized results keyed by carrier|code|description. A large batch invoice resolves the
     * same handful of descriptions ("Fuel Surcharge", …) thousands of times; caching turns that
     * from O(charges × mappings) into O(distinct descriptions × mappings).
     *
     * @var array<string, ?int>
     */
    private array $cache = [];

    public function resolve(?int $carrierId, ?string $code, ?string $description): ?int
    {
        $code = $code !== null ? trim($code) : null;
        $description = $description !== null ? trim($description) : null;

        $cacheKey = ($carrierId ?? 'n').'|'.((string) $code).'|'.((string) $description);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // A correction line prefixes the correction onto the description. An ADDRESS correction is a
        // flat fee, so its line IS the fee (→ Address Correction) even when labelled with a service
        // ("Address Correction Ground" is a fixed $20.20, not the ~$8 real transport). A SHIPPING
        // CHARGE correction is a real re-rate, so its line keeps the underlying transport/fuel
        // category. In both, a named non-base surcharge (fuel) keeps its own category. The driver
        // dimension carries the "why" separately.
        if ($description !== null && $description !== '') {
            foreach (self::CORRECTION_PREFIXES as $prefix => [$feeCategory, $transportIsFee]) {
                if (stripos($description, $prefix) === 0) {
                    return $this->cache[$cacheKey] = $this->correctionCategory(
                        $carrierId, $feeCategory, $transportIsFee, trim(substr($description, strlen($prefix)))
                    );
                }
            }
        }

        foreach ($this->sortedMappings() as $mapping) {
            if ($mapping->carrier_id !== null && $mapping->carrier_id !== $carrierId) {
                continue;
            }

            if ($mapping->match_type === ChargeCodeMapping::MATCH_CODE) {
                if ($code !== null && $code !== '' && strcasecmp($code, $mapping->match_value) === 0) {
                    return $this->cache[$cacheKey] = $mapping->charge_category_id;
                }
            } elseif ($description !== null && $description !== '' && stripos($description, $mapping->match_value) !== false) {
                return $this->cache[$cacheKey] = $mapping->charge_category_id;
            }
        }

        return $this->cache[$cacheKey] = null;
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

    private function correctionCategory(?int $carrierId, string $feeCategory, bool $transportIsFee, string $remainder): ?int
    {
        if ($remainder === '') {
            return $this->categoryIdByName($feeCategory);
        }

        $remainderCategory = $this->resolve($carrierId, null, $remainder);

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
