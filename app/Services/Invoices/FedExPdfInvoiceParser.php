<?php

namespace App\Services\Invoices;

/**
 * Parses charges out of a FedEx invoice PDF's extracted text.
 *
 * FedEx PDFs have no aggregate surcharge summary — charges live in per-shipment
 * blocks that flatten to "<labels...> <amounts...> Total Charge USD $X". Labels
 * and amounts come out in the SAME order, so we pair them by index and only
 * accept a shipment when the amounts reconcile to its printed Total Charge.
 * Non-reconciling blocks are skipped (counted) rather than guessed — accuracy
 * over coverage. Invoices that DO have a CSV always use the CSV instead.
 */
class FedExPdfInvoiceParser
{
    /**
     * Known FedEx charge labels, longest-first for greedy matching.
     *
     * @var array<int, string>
     */
    protected array $labels = [
        'Regularly Scheduled Pickup Mon-Fri',
        'DAS Extended Commercial',
        'DAS Extended Comm',
        'DAS Remote Comm',
        'DAS HI Residential',
        'DAS Extended Resi',
        'DAS Comm',
        'DAS Resi',
        'DAS',
        'Transportation Charge',
        'Performance Pricing',
        'Earned Discount',
        'Fuel Surcharge',
        'Address Correction',
        'Demand-Oversize',
        "Demand-Add'l Handling",
        'Demand Surcharge',
        'Oversize Charge',
        'AHS - Dimensions',
        'AHS - Weight',
        "Add'l Handling-Dimension",
        'Additional Handling',
        'Residential',
        'Weekday Delivery',
        'Discount',
    ];

    /**
     * @return array{shipments: array<int, array{tracking: ?string, total: float, charges: array<int, array{description: string, amount: float}>}>, reconciled: int, skipped: int}
     */
    public function extract(string $text): array
    {
        // Drop note lines (prefixed with the \177 marker) — they contain
        // percentages like "24.50%" that masquerade as charge amounts.
        $clean = preg_replace('/\x7f[^\x7f]*?(?=\x7f|Automation|Tracking ID|$)/u', ' ', $text) ?? $text;

        $segments = preg_split('/Total Charge USD \$([0-9,]+\.\d{2})/', $clean, -1, PREG_SPLIT_DELIM_CAPTURE);

        $shipments = [];
        $reconciled = 0;
        $skipped = 0;

        $count = is_array($segments) ? count($segments) : 0;
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $segment = (string) $segments[$i];
            $total = (float) str_replace(',', '', (string) $segments[$i + 1]);

            // The charge table sits just before the Total, but how far back the
            // base "Transportation Charge" lands varies by layout. Grow the scan
            // window until the amounts reconcile to the printed Total.
            $labels = [];
            $amounts = [];
            $reconcileOk = false;
            foreach ([400, 900, 1800, strlen($segment)] as $width) {
                [$labels, $amounts] = $this->tokenize(substr($segment, -$width));
                if (count($labels) > 0 && count($labels) === count($amounts)
                    && abs(array_sum($amounts) - $total) <= 0.02) {
                    $reconcileOk = true;
                    break;
                }
            }

            if (! $reconcileOk) {
                $skipped++;

                continue;
            }

            $charges = [];
            foreach ($labels as $j => $label) {
                $charges[] = ['description' => $label, 'amount' => $amounts[$j]];
            }

            $shipments[] = [
                'tracking' => $this->findTracking($segment),
                'total' => $total,
                'charges' => $charges,
            ];
            $reconciled++;
        }

        return ['shipments' => $shipments, 'reconciled' => $reconciled, 'skipped' => $skipped];
    }

    /**
     * Walk the window left-to-right, emitting known labels and decimal amounts
     * in the order they appear; skip everything else (noise).
     *
     * @return array{0: array<int, string>, 1: array<int, float>}
     */
    protected function tokenize(string $window): array
    {
        $labels = [];
        $amounts = [];
        $len = strlen($window);
        $pos = 0;

        while ($pos < $len) {
            if ($window[$pos] === ' ') {
                $pos++;

                continue;
            }

            $matchedLabel = null;
            foreach ($this->labels as $label) {
                if (strncasecmp(substr($window, $pos, strlen($label)), $label, strlen($label)) === 0) {
                    $matchedLabel = $label;
                    break;
                }
            }
            if ($matchedLabel !== null) {
                $labels[] = $matchedLabel;
                $pos += strlen($matchedLabel);

                continue;
            }

            if (preg_match('/^-?[0-9,]+\.\d{2}(?![0-9%])/', substr($window, $pos, 16), $m)) {
                $amounts[] = (float) str_replace(',', '', $m[0]);
                $pos += strlen($m[0]);

                continue;
            }

            // Advance past the current run of non-space characters.
            $next = strpos($window, ' ', $pos);
            $pos = $next === false ? $len : $next + 1;
        }

        return [$labels, $amounts];
    }

    protected function findTracking(string $segment): ?string
    {
        // FedEx Express = 12 digits; Ground = 15 digits.
        if (preg_match('/\b(\d{12,22})\b/', $segment, $m)) {
            return $m[1];
        }

        return null;
    }
}
