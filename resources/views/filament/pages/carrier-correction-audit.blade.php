<x-filament-panels::page>
    <x-filament::section collapsible collapsed icon="heroicon-o-information-circle">
        <x-slot name="heading">How to read this view</x-slot>
        <x-slot name="description">Which carrier flags corrections more aggressively, normalized per 1,000 shipments</x-slot>
        <div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
            <p>How many corrections each carrier raised <strong>per 1,000 shipments</strong> — so unequal shipment volume doesn't distort it. <span class="font-medium">Rate = corrections ÷ that carrier's total shipments × 1,000.</span> A higher rate means that carrier penalizes addresses more readily.</p>

            <p><span class="font-medium">▸ All Corrections</span> is the reliable headline — it counts every Address-Correction <em>charge</em> (captured for both carriers). The indented <span class="font-medium">"(graded)"</span> rows break it down by severity/what-changed, but only cover corrections where we captured the original→corrected <em>address detail</em>: that's ~complete for UPS, but <strong>partial for FedEx</strong> (most FedEx corrections arrive via CSV, which records the fee but not the address pair — only FedEx PDFs carry the detail). So trust the headline row for the rate; treat the FedEx graded rows as a small sample.</p>

            <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <span class="font-semibold">Read with care —</span> we were UPS-heavy through ~2013 and FedEx-heavy after ~2023, and the two carriers may receive different *kinds* of addresses. For a fair comparison, set the <strong>Year</strong> filter to a window where both carriers shipped meaningfully (e.g. 2013–2018, 2023–2026). A rate gap across non-overlapping years reflects era/address-mix, not carrier behavior.
            </div>

            <p><span class="font-medium">Why it matters:</span> if one carrier flags suite-omissions or formatting far more often, that carrier is functionally more expensive for those addresses — feed that into routing (e.g. add a "correction risk" surcharge to the aggressive carrier when an address lacks a suite).</p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
