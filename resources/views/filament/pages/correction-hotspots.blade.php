<x-filament-panels::page>
    <x-filament::section collapsible collapsed icon="heroicon-o-information-circle">
        <x-slot name="heading">How to read this view</x-slot>
        <x-slot name="description">Geographic clusters that repeatedly trigger correction fees</x-slot>
        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
            <p>Each row is a <strong>street cluster</strong> (first 16 characters of the original street, within one zip) that incurred multiple address-correction fees. These are structural problem spots — multi-unit buildings, industrial parks, and bad-data addresses — where fixing the address once stops a recurring fee.</p>
            <ul class="space-y-1">
                <li><span class="font-medium">Corrections / Total Fees</span> — how many times this cluster was corrected and what it cost.</li>
                <li><span class="font-medium">Main Issue</span> — the most common thing that changed there (e.g. "Suite Changed" = the carrier keeps demanding a unit number).</li>
            </ul>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
