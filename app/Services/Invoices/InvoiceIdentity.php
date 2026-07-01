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
        // Strip leading zeros too — UPS zero-pads the invoice number differently in
        // the CSV (000000691317025) vs the PDF (0000691317025); both are 691317025.
        $normalized = ltrim(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? ''), '0');

        return $normalized === '' ? null : $normalized;
    }

    public static function account(?string $value): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
