<?php

namespace App\Services\Invoices;

/**
 * Normalizes carrier invoice / account identifiers so the same invoice matches
 * across formats. FedEx prints the invoice number with dashes in the PDF
 * ("9-148-48578") but bare in the CSV ("914848578"); stripping non-alphanumerics
 * makes them equal. Same for account numbers ("0672-0104-3" vs "067201043").
 */
class InvoiceIdentity
{
    public static function number(?string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function account(?string $value): ?string
    {
        return self::number($value);
    }
}
