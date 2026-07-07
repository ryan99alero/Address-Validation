<?php

namespace App\Services\Invoices;

use App\Enums\ChargeDriver;

/**
 * Determines a charge's DRIVER (why we were billed) from the strongest signal available, in
 * precedence order: the carrier's own billing code (UPS CSV col "Charge Category Detail Code" /
 * FedEx code) → the UPS PDF section it printed under → a description rule (FedEx flat text) →
 * default "normal". Deterministic and structural, so it's plain code rather than a rules table.
 *
 * Returns [driver key, source] where source is one of csv_code | pdf_section | description | default.
 */
class ChargeDriverResolver
{
    /**
     * Carrier billing code → driver. UPS uses these on every CSV line back to 2009; FedEx's
     * ADDCOR is included too. Codes don't collide across carriers.
     *
     * @var array<string, ChargeDriver>
     */
    private const CODE_DRIVERS = [
        // UPS normal shipment entry channels
        'ISS' => ChargeDriver::Normal, 'MAN' => ChargeDriver::Normal, 'WWS' => ChargeDriver::Normal,
        'HND' => ChargeDriver::Normal, 'FC' => ChargeDriver::Normal, 'TP' => ChargeDriver::Normal,
        // UPS adjustments / corrections
        'ADC' => ChargeDriver::AddressCorrection,
        'SCC' => ChargeDriver::AuditCorrection,
        'RADJ' => ChargeDriver::ResidentialReclass,
        'ZONE' => ChargeDriver::ZoneCorrection,
        'CLB' => ChargeDriver::NotPreviouslyBilled,
        'RTS' => ChargeDriver::Returned, 'RS' => ChargeDriver::Returned, 'DIN' => ChargeDriver::Returned,
        'CHBK' => ChargeDriver::ThirdPartyChargeback,
        'CADJ' => ChargeDriver::BillingAdjustment,
        'VOID' => ChargeDriver::Voided,
        'FEES' => ChargeDriver::LateFee,
        'SVCH' => ChargeDriver::ServiceFee,
        // FedEx
        'ADDCOR' => ChargeDriver::AddressCorrection,
    ];

    /**
     * UPS PDF section key (as stored on carrier_shipments.section) → driver.
     *
     * @var array<string, ChargeDriver>
     */
    private const SECTION_DRIVERS = [
        'outbound' => ChargeDriver::Normal,
        'inbound' => ChargeDriver::Normal,
        'address_correction' => ChargeDriver::AddressCorrection,
        'shipping_charge_correction' => ChargeDriver::AuditCorrection,
        'packages_not_billed' => ChargeDriver::NotPreviouslyBilled,
        'adjustments' => ChargeDriver::BillingAdjustment,
        'service' => ChargeDriver::ServiceFee,
    ];

    /**
     * @return array{0: string, 1: string} [driver value, source]
     */
    public function resolve(?string $code, ?string $section, ?string $description): array
    {
        $code = $code !== null ? strtoupper(trim($code)) : '';
        if ($code !== '' && isset(self::CODE_DRIVERS[$code])) {
            return [self::CODE_DRIVERS[$code]->value, 'csv_code'];
        }

        $section = $section !== null ? strtolower(trim($section)) : '';
        if ($section !== '' && isset(self::SECTION_DRIVERS[$section])) {
            return [self::SECTION_DRIVERS[$section]->value, 'pdf_section'];
        }

        if (($driver = $this->fromDescription($description)) !== null) {
            return [$driver->value, 'description'];
        }

        return [ChargeDriver::Normal->value, 'default'];
    }

    /**
     * Conservative description rules — the FedEx path, where there's no code or section.
     */
    private function fromDescription(?string $description): ?ChargeDriver
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/address correction/i', $description) => ChargeDriver::AddressCorrection,
            (bool) preg_match('/shipping charge correction|rated weight|weight correction|dim(?:ensional)? (?:adjust|correct)/i', $description) => ChargeDriver::AuditCorrection,
            (bool) preg_match('/invalid account|chargeback/i', $description) => ChargeDriver::ThirdPartyChargeback,
            (bool) preg_match('/return to sender|reroute|reschedul/i', $description) => ChargeDriver::Returned,
            default => null,
        };
    }
}
