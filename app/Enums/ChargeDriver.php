<?php

namespace App\Enums;

/**
 * WHY a carrier charge exists — its driver — as opposed to WHAT it is (the charge category).
 * A normal shipment, an address-correction re-rate, a DIM/weight audit, a return, etc. The code
 * switches on these values; the operator-editable presentation/config layer lives in the
 * charge_drivers catalog table (seeded from these defaults). See docs review with Fable 5.
 */
enum ChargeDriver: string
{
    case Normal = 'normal';
    case AddressCorrection = 'address_correction';
    case AuditCorrection = 'audit_correction';           // DIM / weight / service re-rate
    case ResidentialReclass = 'residential_reclass';
    case ZoneCorrection = 'zone_correction';
    case NotPreviouslyBilled = 'not_previously_billed';
    case Returned = 'return';                             // return / reroute / reschedule / RTS
    case ThirdPartyChargeback = 'third_party_chargeback';
    case BillingAdjustment = 'billing_adjustment';
    case Voided = 'void';
    case LateFee = 'late_fee';
    case ServiceFee = 'service_fee';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal Shipment',
            self::AddressCorrection => 'Address Correction',
            self::AuditCorrection => 'DIM / Weight Audit',
            self::ResidentialReclass => 'Residential Reclassification',
            self::ZoneCorrection => 'Zone Correction',
            self::NotPreviouslyBilled => 'Not Previously Billed',
            self::Returned => 'Return / Reroute',
            self::ThirdPartyChargeback => 'Third-Party Chargeback',
            self::BillingAdjustment => 'Billing Adjustment',
            self::Voided => 'Void / Credit',
            self::LateFee => 'Late Fee',
            self::ServiceFee => 'Service Charge',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::Normal => 'SHIP',
            self::AddressCorrection => 'ADRC',
            self::AuditCorrection => 'AUDIT',
            self::ResidentialReclass => 'RECLS',
            self::ZoneCorrection => 'ZONE',
            self::NotPreviouslyBilled => 'NPB',
            self::Returned => 'RTN',
            self::ThirdPartyChargeback => '3PCB',
            self::BillingAdjustment => 'BADJ',
            self::Voided => 'VOID',
            self::LateFee => 'LATE',
            self::ServiceFee => 'SVC',
        };
    }

    public function disposition(): ChargeDisposition
    {
        return match ($this) {
            self::AddressCorrection, self::Returned => ChargeDisposition::CustomerChargebackable,
            self::AuditCorrection, self::ResidentialReclass, self::ZoneCorrection, self::ThirdPartyChargeback => ChargeDisposition::CarrierDisputable,
            default => ChargeDisposition::Informational,
        };
    }

    /**
     * Badge color hint for the UI (Filament color name).
     */
    public function color(): string
    {
        return match ($this->disposition()) {
            ChargeDisposition::CustomerChargebackable => 'warning',
            ChargeDisposition::CarrierDisputable => 'info',
            ChargeDisposition::Informational => $this === self::LateFee ? 'danger' : 'gray',
        };
    }
}
