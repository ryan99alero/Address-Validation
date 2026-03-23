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
                $originalNormalized = $normalizePostal($this->result->input_postal);
                $correctedFull = $normalizePostal(
                    $this->result->output_postal .
                    ($this->result->output_postal_ext ?? '')
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
            $hasCorrections = $isChanged($this->result->output_address_1, $this->result->input_address_1)
                || $isChanged($this->result->output_city, $this->result->input_city)
                || $isChanged($this->result->output_state, $this->result->input_state)
                || $isPostalChanged();

            $isValid = $this->result->validation_status === 'valid';
            $isAmbiguous = $this->result->validation_status === 'ambiguous';

            // Determine status label and styling
            if ($isValid) {
                $statusLabel = $hasCorrections ? 'Address Corrected' : 'Address Matched';
                $statusColor = $hasCorrections ? 'warning' : 'success';
                $statusIcon = $hasCorrections ? 'heroicon-o-pencil-square' : 'heroicon-o-check-circle';
                $statusBgClass = $hasCorrections ? 'bg-warning-50 dark:bg-warning-950' : 'bg-success-50 dark:bg-success-950';
            } elseif ($isAmbiguous) {
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

            // Get carrier slug for carrier-specific analysis
            $carrierSlug = $this->result->validatedByCarrier?->slug;
            $analysisData = [];

            // Note: Raw response data would need to be stored separately if needed
            // The denormalized schema doesn't store raw_response on Address
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
                            @if($this->result->input_name)
                                <p class="font-medium text-gray-950 dark:text-white">{{ $this->result->input_name }}</p>
                            @endif
                            @if($this->result->input_company)
                                <p class="text-gray-600 dark:text-gray-400">{{ $this->result->input_company }}</p>
                            @endif
                            <p class="text-gray-950 dark:text-white mt-1">{{ $this->result->input_address_1 }}</p>
                            @if($this->result->input_address_2)
                                <p class="text-gray-950 dark:text-white">{{ $this->result->input_address_2 }}</p>
                            @endif
                            <p class="text-gray-950 dark:text-white">
                                {{ $this->result->input_city }}, {{ $this->result->input_state }} {{ $this->result->input_postal }}
                            </p>
                            <p class="text-gray-500 dark:text-gray-400">{{ $this->result->input_country }}</p>
                        </div>
                    </x-filament::section>

                    {{-- Corrected/Validated Address Card --}}
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center justify-between w-full">
                                <span class="text-base font-medium text-gray-500 dark:text-gray-400">
                                    {{ $isValid ? 'Validated Address' : 'Suggested Address' }}
                                </span>
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    x-on:click="
                                        const address = [
                                            '{{ $this->result->input_name }}',
                                            '{{ $this->result->input_company }}',
                                            '{{ $this->result->output_address_1 }}',
                                            '{{ $this->result->output_address_2 }}',
                                            '{{ $this->result->output_city }}, {{ $this->result->output_state }} {{ $this->result->getFullPostalCode() }}',
                                            '{{ $this->result->output_country ?: $this->result->input_country }}'
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
                            @if($this->result->input_name)
                                <p class="font-medium text-gray-950 dark:text-white">{{ $this->result->input_name }}</p>
                            @endif
                            @if($this->result->input_company)
                                <p class="text-gray-600 dark:text-gray-400">{{ $this->result->input_company }}</p>
                            @endif

                            {{-- Address Line 1 --}}
                            @if($this->result->output_address_1)
                                <p class="mt-1 {{ $isChanged($this->result->output_address_1, $this->result->input_address_1) ? 'bg-primary-100 dark:bg-primary-900 px-1.5 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">
                                    {{ $this->result->output_address_1 }}
                                </p>
                            @endif

                            {{-- Address Line 2 --}}
                            @if($this->result->output_address_2)
                                <p class="text-gray-950 dark:text-white">{{ $this->result->output_address_2 }}</p>
                            @endif

                            {{-- City, State ZIP --}}
                            <p>
                                <span class="{{ $isChanged($this->result->output_city, $this->result->input_city) ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->output_city }}</span><span class="text-gray-950 dark:text-white">,</span>
                                <span class="{{ $isChanged($this->result->output_state, $this->result->input_state) ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->output_state }}</span>
                                <span class="{{ $isPostalChanged() ? 'bg-primary-100 dark:bg-primary-900 px-1 py-0.5 rounded font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-950 dark:text-white' }}">{{ $this->result->getFullPostalCode() }}</span>
                            </p>

                            {{-- Country --}}
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ $this->result->output_country ?: $this->result->input_country }}
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
                        {{-- Validation Status --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Status</p>
                            <x-filament::badge :color="$statusColor" size="sm">
                                {{ ucfirst($this->result->validation_status) }}
                            </x-filament::badge>
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
                            @php $confidence = ($this->result->confidence_score ?? 0) * 100; @endphp
                            @if($confidence > 0)
                                <x-filament::badge :color="$confidence >= 90 ? 'success' : ($confidence >= 50 ? 'warning' : 'danger')" size="sm">
                                    {{ number_format($confidence) }}%
                                </x-filament::badge>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endif
                        </div>

                        {{-- Residential --}}
                        <div>
                            <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">Residential</p>
                            @if($this->result->is_residential === true)
                                <x-filament::badge color="info" size="sm">Yes</x-filament::badge>
                            @elseif($this->result->is_residential === false)
                                <x-filament::badge color="gray" size="sm">No</x-filament::badge>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">—</span>
                            @endif
                        </div>
                    </div>
                </x-filament::section>

                {{-- Carrier Info Footer --}}
                <div class="mt-4 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-truck" class="h-4 w-4" />
                    <span>Validated by <strong>{{ $this->result->validatedByCarrier?->name ?? 'Unknown Carrier' }}</strong></span>
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4" />
                    <span>{{ $this->result->validated_at?->format('M j, Y g:i A') ?? 'Unknown' }}</span>
                </div>
            </x-filament::section>

            {{-- Transit Times Section --}}
            @if($this->transitTimes && $this->transitTimes->isNotEmpty())
                <x-filament::section class="mt-6" collapsible>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-primary-500" />
                            <span class="text-sm font-medium">Shipping Options & Transit Times</span>
                            <x-filament::badge color="info" size="sm">{{ $this->transitTimes->count() }} services</x-filament::badge>
                        </div>
                    </x-slot>

                    <div class="space-y-3">
                        {{-- Express Services --}}
                        @php $expressServices = $this->transitTimes->filter(fn($t) => !in_array($t->service_type, ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY', 'SMART_POST'])); @endphp
                        @if($expressServices->isNotEmpty())
                            <div>
                                <h4 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2 font-medium">Express Services</h4>
                                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Service</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Transit Time</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Delivery By</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cutoff</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach($expressServices as $transit)
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                                    <td class="px-4 py-2 whitespace-nowrap">
                                                        <span class="font-medium text-gray-900 dark:text-white">{{ $transit->service_label }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                                        {{ $transit->transit_range }}
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap">
                                                        @if($transit->formatted_delivery_date)
                                                            <span class="text-gray-900 dark:text-white">{{ $transit->formatted_delivery_date }}</span>
                                                            @if($transit->formatted_delivery_time)
                                                                <span class="text-gray-500 dark:text-gray-400 text-xs ml-1">by {{ $transit->formatted_delivery_time }}</span>
                                                            @endif
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                                        @if($transit->cutoff_time)
                                                            {{ \Carbon\Carbon::parse($transit->cutoff_time)->format('g:i A') }}
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        {{-- Ground Services --}}
                        @php $groundServices = $this->transitTimes->filter(fn($t) => in_array($t->service_type, ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY'])); @endphp
                        @if($groundServices->isNotEmpty())
                            <div>
                                <h4 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2 font-medium">Ground Services</h4>
                                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Service</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Transit Time</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Delivery By</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Distance</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                            @foreach($groundServices as $transit)
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                                    <td class="px-4 py-2 whitespace-nowrap">
                                                        <span class="font-medium text-gray-900 dark:text-white">{{ $transit->service_label }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                                        {{ $transit->transit_range }}
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap">
                                                        @if($transit->formatted_delivery_date)
                                                            <span class="text-gray-900 dark:text-white">{{ $transit->formatted_delivery_date }}</span>
                                                        @else
                                                            <span class="text-gray-400">—</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-400">
                                                        {{ $transit->formatted_distance ?? '—' }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Transit Times Footer --}}
                    <div class="mt-4 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4" />
                            <span>From: <strong>{{ $this->data['origin_postal_code'] ?? 'Unknown' }}</strong></span>
                        </div>
                        <x-filament::button
                            size="xs"
                            color="gray"
                            wire:click="refreshTransitTimes"
                            wire:loading.attr="disabled"
                            icon="heroicon-o-arrow-path"
                        >
                            <span wire:loading.remove wire:target="refreshTransitTimes">Refresh</span>
                            <span wire:loading wire:target="refreshTransitTimes">Loading...</span>
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @elseif($isValid && ($this->data['include_transit_times'] ?? false) && !empty($this->data['origin_postal_code']))
                {{-- Show loading state or prompt to fetch --}}
                <x-filament::section class="mt-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                            <span>Transit times available</span>
                        </div>
                        <x-filament::button
                            size="sm"
                            wire:click="refreshTransitTimes"
                            wire:loading.attr="disabled"
                            icon="heroicon-o-arrow-path"
                        >
                            <span wire:loading.remove wire:target="refreshTransitTimes">Fetch Transit Times</span>
                            <span wire:loading wire:target="refreshTransitTimes">Loading...</span>
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
