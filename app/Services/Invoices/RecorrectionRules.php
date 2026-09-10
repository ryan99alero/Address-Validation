<?php

namespace App\Services\Invoices;

use App\Models\CorrectedAddress;
use Carbon\Carbon;
use Throwable;

/**
 * Shared rules for the Re-Corrections (AddressSupersession) queue, used by BOTH the nightly reverify
 * job (going forward) and the cleanup command (existing rows) so the two can never drift apart.
 */
class RecorrectionRules
{
    /**
     * A "fee-free reformat": the carrier returned the SAME deliverable location (city + state + ZIP5)
     * and merely dropped a trailing secondary unit from address_1 (new is a whole-token prefix of old,
     * e.g. "405 babcock blvd w ste110" -> "405 babcock blvd w"). This is not a chargeable correction,
     * so it should never sit in the review queue. A real change — different street (E->W), a different
     * city/ZIP, or an ADDED unit — is NOT fee-free and stays reviewable. Actual invoice correction fees
     * arrive on a separate (recorrection) path and are unaffected either way.
     *
     * @param  array{address_1?:?string, city?:?string, state?:?string, postal?:?string}  $old
     * @param  array{address_1?:?string, city?:?string, state?:?string, postal?:?string}  $new
     */
    public static function isFeeFreeReformat(array $old, array $new): bool
    {
        if (self::zip5($old['postal'] ?? null) !== self::zip5($new['postal'] ?? null)) {
            return false;
        }
        if (CorrectedAddress::normalize($old['state'] ?? null) !== CorrectedAddress::normalize($new['state'] ?? null)) {
            return false;
        }
        if (CorrectedAddress::normalize($old['city'] ?? null) !== CorrectedAddress::normalize($new['city'] ?? null)) {
            return false;
        }

        $oldLine = CorrectedAddress::normalize($old['address_1'] ?? null);
        $newLine = CorrectedAddress::normalize($new['address_1'] ?? null);
        if ($oldLine === '' || $newLine === '' || $oldLine === $newLine) {
            return false;
        }

        // Carrier dropped a trailing token (the suite/unit): new + " ..." == old.
        return str_starts_with($oldLine, $newLine.' ');
    }

    /**
     * Normalize a supersession reference date. Old UPS invoices carry 2-digit years that parsed to
     * year 00xx (e.g. "0020-11-07"); recover those to 20xx. Anything still implausible (pre-2005 or
     * more than a year in the future) becomes null rather than showing a fake date like "Nov 7, 0020".
     */
    public static function sanitizeDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }

        if ($date->year < 100) {
            $date = $date->addYears(2000); // 2-digit-year recovery: 0020 -> 2020
        }

        if ($date->year < 2005 || $date->year > (int) Carbon::now()->year + 1) {
            return null;
        }

        return $date->toDateString();
    }

    private static function zip5(?string $postal): string
    {
        return substr((string) preg_replace('/[^0-9]/', '', (string) $postal), 0, 5);
    }
}
