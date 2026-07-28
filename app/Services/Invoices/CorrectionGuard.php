<?php

namespace App\Services\Invoices;

use App\Models\CarrierInvoiceLine;
use App\Models\CompanySetting;
use App\Models\ZipCentroid;

/**
 * Decides whether "the carrier says address FROM should be address TO" is safe to auto-apply. Pure:
 * two address forms in, a verdict out. Objective is fee avoidance, so we WANT to follow the carrier —
 * but not blindly: corrections to our own origin, to a degenerate address, or across states/long
 * distances are the carrier being wrong (e.g. Houston TX -> Arkansas) and must never silently rewrite
 * the cache.
 */
class CorrectionGuard
{
    public const APPLY = 'apply';        // safe: same building, local, sane target — thread it

    public const REVIEW = 'review';      // plausible but risky — record for a human

    public const REJECT = 'reject';      // garbage — never apply, deactivate on backfill

    public function __construct(
        private ?int $reviewDistanceMiles = null,
        private ?int $garbageDistanceMiles = null,
    ) {}

    /**
     * @param  array{address_1?: ?string, city?: ?string, state?: ?string, postal?: ?string}  $from
     * @param  array{address_1?: ?string, city?: ?string, state?: ?string, postal?: ?string}  $to
     * @return array{verdict: string, reason: string, distance_miles: ?float}
     */
    public function evaluate(array $from, array $to): array
    {
        $reviewMiles = $this->reviewDistanceMiles ?? (int) config('correction_cache.guard_distance_miles', 50);
        $garbageMiles = $this->garbageDistanceMiles ?? (int) config('correction_cache.garbage_distance_miles', 200);

        $toStreet = trim((string) ($to['address_1'] ?? ''));
        $toZip5 = $this->zip5($to['postal'] ?? null);

        if (mb_strlen($toStreet) < 4 || strlen($toZip5) !== 5) {
            return $this->result(self::REJECT, 'degenerate_target', null);
        }

        if ($this->isOwnOrigin($toStreet, $toZip5)) {
            return $this->result(self::REJECT, 'own_origin_address', null);
        }

        $distance = $this->distanceMiles($this->zip5($from['postal'] ?? null), $toZip5);

        $stateChanged = $this->norm($from['state'] ?? '') !== $this->norm($to['state'] ?? '');
        if ($stateChanged) {
            // A different state AND far away = the carrier corrected to a genuinely different place.
            if ($distance !== null && $distance > $garbageMiles) {
                return $this->result(self::REJECT, 'garbage_far_state', $distance);
            }

            return $this->result(self::REVIEW, 'state_changed', $distance);
        }

        // Same state but the carrier moved us to a different building (house number / street name).
        if (CarrierInvoiceLine::streetCore($from['address_1'] ?? '') !== CarrierInvoiceLine::streetCore($toStreet)) {
            return $this->result(self::REVIEW, 'street_core_changed', $distance);
        }

        if ($distance !== null && $distance > $reviewMiles) {
            return $this->result(self::REVIEW, 'distance_exceeded', $distance);
        }

        return $this->result(self::APPLY, 'ok', $distance);
    }

    private function isOwnOrigin(string $street, string $zip5): bool
    {
        $company = CompanySetting::instance();
        if (empty($company->address_line_1) || empty($company->postal_code)) {
            return false;
        }

        $ownCore = CarrierInvoiceLine::streetCore($company->address_line_1);
        $toCore = CarrierInvoiceLine::streetCore($street);

        return $ownCore !== '' && $ownCore === $toCore && $zip5 === $this->zip5($company->postal_code);
    }

    /**
     * Haversine miles between two ZIP centroids; null when either ZIP has no centroid (skip the check).
     */
    private function distanceMiles(string $fromZip5, string $toZip5): ?float
    {
        if ($fromZip5 === '' || $toZip5 === '' || $fromZip5 === $toZip5) {
            return $fromZip5 === $toZip5 && $fromZip5 !== '' ? 0.0 : null;
        }

        $a = ZipCentroid::where('zip', $fromZip5)->first();
        $b = ZipCentroid::where('zip', $toZip5)->first();
        if ($a === null || $b === null) {
            return null;
        }

        $earth = 3958.8;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * asin(min(1.0, sqrt($h))), 1);
    }

    private function zip5(?string $postal): string
    {
        return substr((string) preg_replace('/[^0-9]/', '', (string) $postal), 0, 5);
    }

    private function norm(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @return array{verdict: string, reason: string, distance_miles: ?float}
     */
    private function result(string $verdict, string $reason, ?float $distance): array
    {
        return ['verdict' => $verdict, 'reason' => $reason, 'distance_miles' => $distance];
    }
}
