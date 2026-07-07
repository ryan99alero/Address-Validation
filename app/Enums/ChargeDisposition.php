<?php

namespace App\Enums;

/**
 * What we can DO about a charge driver — the business action, not the accounting.
 */
enum ChargeDisposition: string
{
    case CustomerChargebackable = 'customer_chargebackable'; // bill it back to the customer (Pace)
    case CarrierDisputable = 'carrier_disputable';           // claim it back from the carrier
    case Informational = 'informational';                    // neither — track / prevent only

    public function label(): string
    {
        return match ($this) {
            self::CustomerChargebackable => 'Charge back to customer',
            self::CarrierDisputable => 'Dispute with carrier',
            self::Informational => 'Informational',
        };
    }
}
