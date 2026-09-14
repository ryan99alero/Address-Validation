<?php

namespace App\Services\Invoices;

/**
 * Who the carrier billed the freight to — a first-class shipment attribute derived from signals both
 * carriers already print on the invoice, so Prepaid / Third-Party / Collect can be filtered, reported,
 * and (for Collect) mapped to Pace's Consignee-Billed without inspecting the shipping cost.
 *
 *  - UPS: section header ("Inbound Collect") + the "Third Party:" bill-to block / "Third Party" in the
 *    service line (both already captured as carrier_shipments.section + is_third_party).
 *  - FedEx: the Service Type column prefix — "Ppd…" / "Collect…" / "Bill 3rd Party…".
 */
class BillingType
{
    public const PREPAID = 'prepaid';

    public const THIRD_PARTY = 'third_party';

    public const COLLECT = 'collect';

    /**
     * UPS: a 3rd-party bill-to wins; otherwise the Inbound (Collect) section; otherwise Prepaid.
     */
    public static function forUps(?string $section, bool $isThirdParty): string
    {
        if ($isThirdParty) {
            return self::THIRD_PARTY;
        }
        if ($section === 'inbound') {
            return self::COLLECT;
        }

        return self::PREPAID;
    }

    /**
     * FedEx: read the Service Type column prefix. When that column holds the real service instead
     * (Express names it there), there's no payment term to read, so fall back to the is_third_party
     * signal (else Prepaid).
     */
    public static function forFedEx(?string $serviceTypeRaw, bool $isThirdParty): string
    {
        return self::fromServiceTerm($serviceTypeRaw)
            ?? ($isThirdParty ? self::THIRD_PARTY : self::PREPAID);
    }

    /**
     * Map a FedEx/UPS payment-term string ("Ppd, Domestic", "Collect, Domestic", "Bill 3rd Party, Dom",
     * "Bill Recipient") to a billing type. Returns null when the string isn't a payment term.
     */
    public static function fromServiceTerm(?string $term): ?string
    {
        $term = trim((string) $term);
        if ($term === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/^Ppd\b/i', $term) => self::PREPAID,
            (bool) preg_match('/^Collect\b/i', $term) => self::COLLECT,
            (bool) preg_match('/^Bill (?:3rd Party|Recipient)\b/i', $term) => self::THIRD_PARTY,
            default => null,
        };
    }
}
