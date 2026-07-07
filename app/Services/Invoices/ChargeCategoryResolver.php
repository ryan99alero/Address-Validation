<?php

namespace App\Services\Invoices;

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

        // The category is WHAT the charge is, not why we got it. Carriers prefix the "why" onto the
        // description ("Address Correction Ground"); strip it so re-rated transport/fuel resolves to
        // its real category. The driver dimension carries the "why" separately.
        $matchDescription = $this->stripDriverPrefix($description);

        foreach ($this->sortedMappings() as $mapping) {
            if ($mapping->carrier_id !== null && $mapping->carrier_id !== $carrierId) {
                continue;
            }

            if ($mapping->match_type === ChargeCodeMapping::MATCH_CODE) {
                if ($code !== null && $code !== '' && strcasecmp($code, $mapping->match_value) === 0) {
                    return $this->cache[$cacheKey] = $mapping->charge_category_id;
                }
            } elseif ($matchDescription !== null && $matchDescription !== '' && stripos($matchDescription, $mapping->match_value) !== false) {
                return $this->cache[$cacheKey] = $mapping->charge_category_id;
            }
        }

        return $this->cache[$cacheKey] = null;
    }

    /**
     * Driver prefixes carriers prepend to the underlying charge description. Removing one exposes
     * the true category ("Address Correction Ground" → "Ground" → Base). A bare prefix with nothing
     * after it (a flat "Address Correction" fee) is left intact so it still resolves to its category.
     *
     * @var array<int, string>
     */
    private const DRIVER_PREFIXES = [
        'Address Correction',
        'Shipping Charge Correction',
        'Chargeback',
        'Not Previously Billed',
        'Returns',
    ];

    private function stripDriverPrefix(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return $description;
        }

        foreach (self::DRIVER_PREFIXES as $prefix) {
            if (stripos($description, $prefix) === 0) {
                $rest = trim(substr($description, strlen($prefix)));

                return $rest !== '' ? $rest : $description;
            }
        }

        return $description;
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
