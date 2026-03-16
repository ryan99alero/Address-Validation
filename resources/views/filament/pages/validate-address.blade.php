<x-filament-panels::page>
    <form wire:submit="validate_address">
        {{ $this->form }}

        <div class="fi-form-actions mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check-badge">
                Validate Address
            </x-filament::button>

            @if($this->result)
                <x-filament::button type="button" wire:click="clearResult" color="gray" icon="heroicon-o-x-mark">
                    Clear Result
                </x-filament::button>
            @endif
        </div>
    </form>

    @if($this->result)
        @php
            // Helper to check if field changed (case-insensitive)
            $isChanged = fn($corrected, $original) => $corrected && strtoupper(trim($corrected)) !== strtoupper(trim($original ?? ''));

            // Helper to normalize postal codes (strips non-digits)
            $normalizePostal = fn($postal) => preg_replace('/[^0-9]/', '', $postal ?? '');

            // Helper to compare postal codes (handles ZIP+4 in various formats)
            $isPostalChanged = function() use ($normalizePostal) {
                $originalNormalized = $normalizePostal($this->savedAddress->postal_code);
                $correctedFull = $normalizePostal(
                    $this->result->corrected_postal_code .
                    ($this->result->corrected_postal_code_ext ?? '')
                );

                // If lengths match, compare full values
                if (strlen($originalNormalized) === strlen($correctedFull)) {
                    return $originalNormalized !== $correctedFull;
                }

                // If original is ZIP+4 (9 digits) and corrected matches, not changed
                if (strlen($originalNormalized) === 9 && $originalNormalized === $correctedFull) {
                    return false;
                }

                // If original is just ZIP (5 digits), only compare base ZIP
                // Adding +4 is an enhancement, not a correction
                $originalBase = substr($originalNormalized, 0, 5);
                $correctedBase = substr($correctedFull, 0, 5);

                return $originalBase !== $correctedBase;
            };

            // Determine if corrections were made (case-insensitive, normalized postal)
            $hasCorrections = $isChanged($this->result->corrected_address_line_1, $this->savedAddress->address_line_1)
                || $isChanged($this->result->corrected_city, $this->savedAddress->city)
                || $isChanged($this->result->corrected_state, $this->savedAddress->state)
                || $isPostalChanged();

            // Determine status label and styling
            if ($this->result->isValid()) {
                $statusLabel = $hasCorrections ? 'Address Corrected' : 'Address Matched';
                $statusColor = $hasCorrections ? 'warning' : 'success';
                $statusIcon = $hasCorrections ? 'heroicon-o-pencil-square' : 'heroicon-o-check-circle';
                $statusBgClass = $hasCorrections ? 'bg-warning-50 dark:bg-warning-950' : 'bg-success-50 dark:bg-success-950';
            } elseif ($this->result->isAmbiguous()) {
                $statusLabel = 'Multiple Matches Found';
                $statusColor = 'warning';
                $statusIcon = 'heroicon-o-question-mark-circle';
                $statusBgClass = 'bg-warning-50 dark:bg-warning-950';
            } else {
                $statusLabel = 'Address Not Found';
                $statusColor = 'danger';
                $statusIcon = 'heroicon-o-x-circle';
                $statusBgClass = 'bg-danger-50 dark:bg-danger-950';
            }

            // Extract analysis data from raw_response
            $rawResponse = $this->result->raw_response ?? [];
            $carrierSlug = $this->result->carrier?->slug;
            $analysisData = [];

            if ($carrierSlug === 'smarty' && isset($rawResponse[0])) {
                $smartyData = $rawResponse[0];
                $analysis = $smartyData['analysis'] ?? [];
                $metadata = $smartyData['metadata'] ?? [];

                $analysisData = [
                    'dpv_match' => match($analysis['dpv_match_code'] ?? null) {
                        'Y' => ['label' => 'Confirmed', 'color' => 'success'],
                        'S' => ['label' => 'Secondary Missing', 'color' => 'warning'],
                        'D' => ['label' => 'Primary Only', 'color' => 'warning'],
                        'N' => ['label' => 'Not Confirmed', 'color' => 'danger'],
                        default => null,
                    },
                    'dpv_vacant' => $analysis['dpv_vacant'] ?? null,
                    'dpv_cmra' => $analysis['dpv_cmra'] ?? null,
                    'dpv_active' => $analysis['active'] ?? null,
                    'county' => $metadata['county_name'] ?? null,
                    'carrier_route' => $metadata['carrier_route'] ?? null,
                    'time_zone' => $metadata['time_zone'] ?? null,
                    'coordinates' => isset($metadata['latitude']) ? [
                        'lat' => $metadata['latitude'],
                        'lng' => $metadata['longitude'],
                    ] : null,
                ];
            } elseif ($carrierSlug === 'fedex') {
                // FedEx stores the resolved address directly (not wrapped in output.resolvedAddresses)
                $fedexData = $rawResponse['output']['resolvedAddresses'][0] ?? $rawResponse;
                $attrs = $fedexData['attributes'] ?? [];

                $analysisData = [
                    'dpv_match' => ($attrs['DPV'] ?? '') === 'true'
                        ? ['label' => 'Confirmed', 'color' => 'success']
                        : ['label' => 'Not Confirmed', 'color' => 'danger'],
                    'po_box' => ($attrs['POBox'] ?? '') === 'true' ? 'Y' : 'N',
                    'zip4_match' => ($attrs['ZIP4Match'] ?? '') === 'true' ? 'Y' : 'N',
                    'address_precision' => $attrs['AddressPrecision'] ?? null,
                    'suite_missing' => ($attrs['SuiteRequiredButMissing'] ?? '') === 'true' ? 'Y' : 'N',
                ];
            } elseif ($carrierSlug === 'ups' && isset($rawResponse['XAVResponse'])) {
                $upsData = $rawResponse['XAVResponse'];

                $analysisData = [
                    'valid_indicator' => isset($upsData['ValidAddressIndicator'])
                        ? ['label' => 'Valid', 'color' => 'success']
                        : (isset($upsData['AmbiguousAddressIndicator'])
                            ? ['label' => 'Ambiguous', 'color' => 'warning']
                            : ['label' => 'Invalid', 'color' => 'danger']),
                ];
            }
        @endphp

        <div class="mt-8">
            {{-- Status Header with Background Color --}}
            <x-filament::section :class="$statusBgClass">
                <x-slot name="heading">
                    <div class="flex items-center gap-3">
                        <x-filament::badge :color="$statusColor" size="lg" :icon="$statusIcon">
                            {{ $statusLabel }}
                        </x-filament::badge>
                    </div>
                </x-slot>

                {{-- Address Comparison - Two Column Grid --}}
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    {{-- Original Address Card --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <span class="text-base font-medium text-gray-500 dark:text-gray-400">Original Address</span>
                        </x-slot>

                        <div class="space-y-0.5 text-sm leading-relaxed">
                            @if($this->savedAddress->name)
                                <p class="font-medium text-gray-950 dark:text-white">{{ $this->savedAddress->name }}</p>
                            @endif
                            @if($this->savedAddress->company)
                                <p class="text-gray-600 dark:text-gray-400">{{ $this->savedAddress->company }}</p>
                            @endif
                            <p class="text-gray-950 dark:text-white mt-1">{{ $this->savedAddress->address_line_1 }}</p>
                            @if($this->savedAddress->address_line_2)
                                <p class="text-gray-950 dark:text-white">{{ $this->savedAddress->address_line_2 }}</p>
                            @endif
                            <p class="text-gray-950 dark:text-white">
                                {{ $this->savedAddress->city }}, {{ $this->savedAddress->state }} {{ $this->savedAddress->postal_code }}
                            </p>
                            <p class="text-gray-500 dark:text-gray-400">{{ $this->savedAddress->country_code }}</p>
                        </div>
                    </x-filament::section>

                    {{-- Corrected/Validated Address Card --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center justify-between w-full">
                                <span class="text-base font-medium text-gray-500 dark:text-gray-400">
                                    {{ $this->result->isValid() ? 'Validated Address' : 'Suggested Address' }}
                                    @if(($this->result->candidates_count ?? 0) > 1)
                                        <span class="text-xs ml-2">(Candidate {{ $this->selectedCandidateIndex + 1 }} of {{ $this->result->candidates_count }})</span>
                                    @endif
                                </span>
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    x-on:click="
                                        const address = [
                                            '{{ $this->savedAddress->name }}',
                                            '{{ $this->savedAddress->company }}',
                                            '{{ $this->result->corrected_address_line_1 }}',
                                            '{{ $this->result->corrected_address_line_2 }}',
                                            '{{ $this->result->corrected_city }}, {{ $this->result->corrected_state }} {{ $this->result->getFullPostalCode() }}',
                                            '{{ $this->result->corrected_country_code ?: $this->savedAddress->country_code }}'
                                        ].filter(line => line.trim()).join('\n');
                                        navigator.clipboard.writeText(address);
                                        copied = true;
                                        setTimeout(() => copied = false, 2000);
                                    "
                                    class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-800 transition-colors"
                                    title="Copy address to clipboard"
                                >
                                    <x-filament::icon
                                        x-show="!copied"
                                        icon="heroicon-o-clipboard-document"
                                        class="h-5 w-5"
                                    />
                                    <x-filament::icon
                                        x-show="copied"
                                        x-cloak
                                        icon="heroicon-o-clipboard-document-check"
                                        class="h-5 w-5 text-success-500"
                                    />
                                </button>
                            </div>
                        </x-slot>

                        <div class="space-y-0.5 text-sm leading-relaxed">
                            {{-- Name & Company (pass-through, not validated by carrier) --}}
                            @if($this->savedAddress->name)
                                <p class="font-medium text-gray-950 dark:text-white">{{ $this->savedAddress->name }}</p>
                            @endif
                            @if($this->savedAddress->company)
                                <p class="text-gray-600 dark:text-gray-400">{{ $this->savedAddress->company }}</p>
                            @endif

                            {{-- Address Line 1 --}}
                            @if($this->result->corrected_address_line_1)
                                <p class="mt-1 {{ $isChanged($this->result->corrected_address_line_1, $this->savedAddress->address_line_1) ? 'bg-primary-100 dark:bg-primary-900 px-1.5 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">
                                    {{ $this->result->corrected_address_line_1 }}
                                </p>
                            @endif

                            {{-- Address Line 2 --}}
                            @if($this->result->corrected_address_line_2)
                                <p class="text-gray-950 dark:text-white">{{ $this->result->corrected_address_line_2 }}</p>
                            @endif

                            {{-- City, State ZIP --}}
                            <p>
                                <span class="{{ $isChanged($this->result->corrected_city, $this->savedAddress->city) ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->corrected_city }}</span><span class="text-gray-950 dark:text-white">,</span>
                                <span class="{{ $isChanged($this->result->corrected_state, $this->savedAddress->state) ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->corrected_state }}</span>
                                <span class="{{ $isPostalChanged() ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->getFullPostalCode() }}</span>
                            </p>

                            {{-- Country --}}
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ $this->result->corrected_country_code ?: $this->savedAddress->country_code }}
                            </p>
                        </div>
                    </x-filament::section>
                </div>

                {{-- Delivery Analysis Section --}}
                <x-filament::section class="mt-6" collapsible>
                    <x-slot name="heading">
                        <span class="text-sm font-medium">Delivery Analysis</span>
                    </x-slot>

                    <div class="grid grid-cols-2 gap-x-8 gap-y-3 md:grid-cols-4 text-sm">
                        {{-- DPV Status (all carriers) --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">DPV Status</p>
                            @if(isset($analysisData['dpv_match']))
                                <x-filament::badge :color="$analysisData['dpv_match']['color']" size="sm">
                                    {{ $analysisData['dpv_match']['label'] }}
                                </x-filament::badge>
                            @elseif(isset($analysisData['valid_indicator']))
                                <x-filament::badge :color="$analysisData['valid_indicator']['color']" size="sm">
                                    {{ $analysisData['valid_indicator']['label'] }}
                                </x-filament::badge>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endif
                        </div>

                        {{-- Classification --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Classification</p>
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ ucfirst($this->result->classification ?? 'Unknown') }}
                            </p>
                        </div>

                        {{-- Confidence --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Confidence</p>
                            @php $confidence = $this->result->confidence_score * 100; @endphp
                            @if($confidence > 0)
                                <x-filament::badge :color="$confidence >= 90 ? 'success' : ($confidence >= 50 ? 'warning' : 'danger')" size="sm">
                                    {{ number_format($confidence) }}%
                                </x-filament::badge>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endif
                        </div>

                        {{-- Candidates --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Candidates</p>
                            @if(($this->result->candidates_count ?? 0) > 1)
                                <button
                                    type="button"
                                    wire:click="openCandidatesModal"
                                    class="font-semibold text-primary-600 dark:text-primary-400 hover:underline cursor-pointer"
                                    title="Click to view all candidates"
                                >
                                    {{ $this->result->candidates_count }} →
                                </button>
                            @else
                                <p class="font-semibold text-gray-950 dark:text-white">
                                    {{ $this->result->candidates_count ?? 0 }}
                                </p>
                            @endif
                        </div>

                        {{-- Smarty-specific fields --}}
                        @if($carrierSlug === 'smarty')
                            {{-- Vacant --}}
                            <div>
                                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Vacant</p>
                                @if($analysisData['dpv_vacant'] === 'Y')
                                    <x-filament::badge color="danger" size="sm">Yes</x-filament::badge>
                                @elseif($analysisData['dpv_vacant'] === 'N')
                                    <x-filament::badge color="success" size="sm">No</x-filament::badge>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </div>

                            {{-- CMRA (Commercial Mail Receiving Agency) --}}
                            <div>
                                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">CMRA</p>
                                @if($analysisData['dpv_cmra'] === 'Y')
                                    <x-filament::badge color="warning" size="sm">Yes</x-filament::badge>
                                @elseif($analysisData['dpv_cmra'] === 'N')
                                    <x-filament::badge color="gray" size="sm">No</x-filament::badge>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </div>

                            {{-- Active Delivery Point --}}
                            <div>
                                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Active</p>
                                @if($analysisData['dpv_active'] === 'Y')
                                    <x-filament::badge color="success" size="sm">Yes</x-filament::badge>
                                @elseif($analysisData['dpv_active'] === 'N')
                                    <x-filament::badge color="danger" size="sm">No</x-filament::badge>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </div>

                            {{-- County --}}
                            @if($analysisData['county'])
                                <div>
                                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">County</p>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $analysisData['county'] }}</p>
                                </div>
                            @endif

                            {{-- Carrier Route --}}
                            @if($analysisData['carrier_route'])
                                <div>
                                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Carrier Route</p>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $analysisData['carrier_route'] }}</p>
                                </div>
                            @endif

                            {{-- Time Zone --}}
                            @if($analysisData['time_zone'])
                                <div>
                                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Time Zone</p>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $analysisData['time_zone'] }}</p>
                                </div>
                            @endif
                        @endif

                        {{-- FedEx-specific fields --}}
                        @if($carrierSlug === 'fedex')
                            {{-- PO Box --}}
                            <div>
                                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">PO Box</p>
                                @if($analysisData['po_box'] === 'Y')
                                    <x-filament::badge color="info" size="sm">Yes</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="sm">No</x-filament::badge>
                                @endif
                            </div>

                            {{-- ZIP+4 Match --}}
                            <div>
                                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">ZIP+4 Match</p>
                                @if($analysisData['zip4_match'] === 'Y')
                                    <x-filament::badge color="success" size="sm">Yes</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="sm">No</x-filament::badge>
                                @endif
                            </div>

                            {{-- Suite Missing --}}
                            @if($analysisData['suite_missing'] === 'Y')
                                <div>
                                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Suite Required</p>
                                    <x-filament::badge color="warning" size="sm">Missing</x-filament::badge>
                                </div>
                            @endif

                            {{-- Address Precision --}}
                            @if($analysisData['address_precision'])
                                <div>
                                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Precision</p>
                                    <p class="font-semibold text-gray-950 dark:text-white text-xs">{{ str_replace('_', ' ', $analysisData['address_precision']) }}</p>
                                </div>
                            @endif
                        @endif
                    </div>
                </x-filament::section>

                {{-- Carrier Info Footer --}}
                <div class="mt-4 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-truck" class="h-4 w-4" />
                    <span>Validated by <strong>{{ $this->result->carrier?->name ?? 'Unknown Carrier' }}</strong></span>
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                    <span>{{ $this->result->validated_at?->format('M j, Y g:i A') ?? 'Unknown' }}</span>
                </div>
            </x-filament::section>
        </div>

        {{-- Candidates Modal --}}
        @if($this->showCandidatesModal)
            <div
                x-data="{ open: true }"
                x-show="open"
                x-on:keydown.escape.window="$wire.closeCandidatesModal()"
                class="fixed inset-0 z-50 overflow-y-auto"
                role="dialog"
                aria-modal="true"
            >
                {{-- Backdrop --}}
                <div
                    x-show="open"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75"
                    wire:click="closeCandidatesModal"
                ></div>

                {{-- Modal Panel --}}
                <div class="flex min-h-full items-center justify-center p-4">
                    <div
                        x-show="open"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="relative w-full max-w-3xl transform overflow-hidden rounded-xl bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10"
                    >
                        {{-- Modal Header --}}
                        <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 px-6 py-4">
                            <h3 class="text-lg font-semibold text-gray-950 dark:text-white">
                                All Candidate Addresses ({{ count($this->getAllCandidates()) }})
                            </h3>
                            <button
                                type="button"
                                wire:click="closeCandidatesModal"
                                class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                            >
                                <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                            </button>
                        </div>

                        {{-- Modal Body --}}
                        <div class="max-h-[60vh] overflow-y-auto p-6">
                            <div class="space-y-4">
                                @foreach($this->getAllCandidates() as $index => $candidate)
                                    <div
                                        wire:click="selectCandidate({{ $index }})"
                                        class="relative cursor-pointer rounded-lg border-2 p-4 transition-all hover:border-primary-500 dark:hover:border-primary-400 {{ $index === $this->selectedCandidateIndex ? 'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-950' : 'border-gray-200 dark:border-gray-700' }}"
                                    >
                                        {{-- Candidate Number Badge --}}
                                        <div class="absolute -top-2 -left-2">
                                            <span class="inline-flex items-center justify-center h-6 w-6 rounded-full text-xs font-bold {{ $index === $this->selectedCandidateIndex ? 'bg-primary-500 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                                                {{ $index + 1 }}
                                            </span>
                                        </div>

                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            {{-- Address --}}
                                            <div class="space-y-1 text-sm">
                                                <p class="font-medium text-gray-950 dark:text-white">
                                                    {{ $candidate['address_line_1'] }}
                                                </p>
                                                @if($candidate['address_line_2'])
                                                    <p class="text-gray-600 dark:text-gray-400">
                                                        {{ $candidate['address_line_2'] }}
                                                    </p>
                                                @endif
                                                <p class="text-gray-950 dark:text-white">
                                                    {{ $candidate['city'] }}, {{ $candidate['state'] }}
                                                    {{ \App\Models\AddressCorrection::formatPostalCode($candidate['postal_code'], $candidate['postal_code_ext'] ?? null) }}
                                                </p>
                                            </div>

                                            {{-- Details --}}
                                            <div class="flex flex-wrap items-start gap-2">
                                                {{-- Confidence --}}
                                                @if(isset($candidate['confidence']) && $candidate['confidence'] > 0)
                                                    @php $conf = $candidate['confidence'] * 100; @endphp
                                                    <x-filament::badge :color="$conf >= 90 ? 'success' : ($conf >= 50 ? 'warning' : 'danger')" size="sm">
                                                        {{ number_format($conf) }}% conf
                                                    </x-filament::badge>
                                                @endif

                                                {{-- Classification --}}
                                                @if(isset($candidate['classification']) && $candidate['classification'] !== 'unknown')
                                                    <x-filament::badge color="gray" size="sm">
                                                        {{ ucfirst($candidate['classification']) }}
                                                    </x-filament::badge>
                                                @endif

                                                {{-- DPV Match Code for Smarty --}}
                                                @if(isset($candidate['dpv_match_code']))
                                                    <x-filament::badge color="info" size="sm">
                                                        DPV: {{ $candidate['dpv_match_code'] }}
                                                    </x-filament::badge>
                                                @endif

                                                {{-- FedEx State --}}
                                                @if(isset($candidate['fedex_state']))
                                                    <x-filament::badge color="info" size="sm">
                                                        {{ $candidate['fedex_state'] }}
                                                    </x-filament::badge>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Selected indicator --}}
                                        @if($index === $this->selectedCandidateIndex)
                                            <div class="absolute top-2 right-2">
                                                <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-primary-500" />
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Modal Footer --}}
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 px-6 py-4">
                            <x-filament::button color="gray" wire:click="closeCandidatesModal">
                                Close
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
