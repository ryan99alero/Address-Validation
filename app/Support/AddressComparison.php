<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Shared renderer for an Original → Corrected address comparison, used across the
 * Addresses, Pace Corrections, and Address Corrections views so the styling is
 * uniform: a labeled field grid where only the changed fields are coloured —
 * red strike-through on the original, green on the corrected.
 */
class AddressComparison
{
    /**
     * Field key => display label, in display order.
     */
    private const FIELDS = [
        'name' => 'Name',
        'company' => 'Company',
        'address1' => 'Street',
        'address2' => 'Suite',
        'address3' => 'Address 3',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'ZIP',
        'country' => 'Country',
    ];

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $corrected
     */
    public static function render(array $original, array $corrected): HtmlString
    {
        $rows = '';

        foreach (self::FIELDS as $key => $label) {
            $old = trim((string) ($original[$key] ?? ''));
            $new = trim((string) ($corrected[$key] ?? ''));

            // The corrected address displays in USPS-standard UPPERCASE (the original
            // stays as received). The comparison below is case-insensitive, so a
            // case-only difference is never flagged as a change.
            if (in_array($key, ['address1', 'address2', 'address3', 'city', 'state', 'country'], true)) {
                $new = strtoupper($new);
            }

            if ($old === '' && $new === '') {
                continue;
            }

            $changed = $new !== '' && strcasecmp($old, $new) !== 0;

            // Unchanged fields render in standard text; only the changed field is
            // coloured + italic — red strike-through on the original, green on the
            // corrected — and its whole row gets a faint highlight so the eye lands
            // on it instantly.
            $oldCell = $changed && $old !== ''
                ? '<span style="color:#f87171;text-decoration:line-through;font-style:italic">'.e($old).'</span>'
                : ($old !== '' ? e($old) : '<span style="color:#6b7280">—</span>');

            $newCell = $changed
                ? '<span style="color:#4ade80;font-weight:700;font-style:italic">'.e($new).'</span>'
                : ($new !== '' ? e($new) : '<span style="color:#6b7280">—</span>');

            $rowAttr = $changed ? ' style="background:rgba(245,158,11,0.10)"' : '';
            $labelStyle = $changed ? 'color:#e5e7eb;font-weight:600' : 'color:#9ca3af';

            $rows .= '<tr'.$rowAttr.'>'
                .'<td style="'.$labelStyle.';padding:1px 14px 1px 0;white-space:nowrap">'.e($label).'</td>'
                .'<td style="padding:1px 18px 1px 0;white-space:nowrap">'.$oldCell.'</td>'
                .'<td style="padding:1px 0;white-space:nowrap">'.$newCell.'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            return new HtmlString('<span style="color:#6b7280">—</span>');
        }

        $header = '<tr style="color:#6b7280;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.03em">'
            .'<td></td>'
            .'<td style="padding:0 18px 4px 0">Original</td>'
            .'<td style="padding:0 0 4px 0">Corrected</td>'
            .'</tr>';

        return new HtmlString('<table style="border-collapse:collapse;font-size:0.8125rem;line-height:1.35">'.$header.$rows.'</table>');
    }

    /**
     * Fallback for log rows that predate the original/corrected snapshots:
     * reconstruct a partial address from a changes diff ('from' or 'to').
     *
     * @param  array<string, array{from?: mixed, to?: mixed}>  $changes
     * @return array<string, mixed>
     */
    public static function fromChanges(array $changes, string $side): array
    {
        $address = [];
        foreach ($changes as $field => $fromTo) {
            $address[$field] = $fromTo[$side] ?? null;
        }

        return $address;
    }
}
