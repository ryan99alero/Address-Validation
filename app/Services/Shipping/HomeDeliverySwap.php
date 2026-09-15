<?php

namespace App\Services\Shipping;

use App\Models\ShipViaCode;

/**
 * Resolves the residential ship-via correction shared by every validation path: a residential FedEx
 * Ground shipment should move to FedEx Home Delivery (the residential-correct service that avoids the
 * residential-on-Ground surcharge), keeping the SAME plant, payer and account. Reuses the BestWay
 * matcher; the plant lives on our ship_via_codes, resolved from the incoming code.
 *
 * Returns null when the incoming ship-via isn't a resolvable FedEx Ground, or there is no Home Delivery
 * equivalent (e.g. UPS Ground — its residential handling is a surcharge, not a separate service).
 */
class HomeDeliverySwap
{
    public function resolve(?string $shipViaCode): ?ShipViaCode
    {
        $code = trim((string) $shipViaCode);
        if ($code === '') {
            return null;
        }

        $original = ShipViaCode::lookup($code);
        if ($original === null || $original->carrier_slug !== 'fedex' || $original->service_type !== 'FEDEX_GROUND') {
            return null;
        }

        $home = ShipViaCode::findMatchingForBestWay('GROUND_HOME_DELIVERY', $original->plant_id, $original);

        return ($home !== null && $home->code !== $original->code) ? $home : null;
    }
}
