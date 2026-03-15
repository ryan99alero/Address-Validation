<x-filament-panels::page>
    @if($step === 1)
        {{-- Step 1: Settings --}}
        <div class="space-y-6">
            <div class="flex items-center gap-4 mb-6">
                <div class="flex items-center justify-center w-10 h-10 rounded-full bg-primary-500 text-white font-bold">
                    1
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Template Settings</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Configure template details and optionally import column headers from a file.</p>
                </div>
            </div>

            {{ $this->settingsForm }}

            <div class="flex justify-end gap-3 pt-4">
                <x-filament::button
                    wire:click="proceedToMapping"
                    icon="heroicon-o-arrow-right"
                    icon-position="after"
                >
                    Continue to Field Mapping
                </x-filament::button>
            </div>
        </div>

    @elseif($step === 2)
        {{-- Step 2: Field Mapping --}}
        <div class="space-y-6">
            <div class="flex items-center gap-4 mb-6">
                <div class="flex items-center justify-center w-10 h-10 rounded-full bg-primary-500 text-white font-bold">
                    2
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Map Fields</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Define which system fields to include in each column of the export.</p>
                </div>
            </div>

            {{-- Field Mapping Grid --}}
            <x-filament::section>
                <x-slot name="heading">Column Layout</x-slot>
                <x-slot name="description">Define your export columns. Drag to reorder, or use the arrow buttons.</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-16">
                                    Position
                                </th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Output Column Header
                                </th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Maps To (System Field)
                                </th>
                                @if(!empty($sampleAddresses))
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Sample Data
                                    </th>
                                @endif
                                <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-32">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($fieldMappings as $index => $mapping)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    {{-- Position --}}
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center justify-center w-8 h-8 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 font-mono text-sm">
                                            {{ chr(65 + $index) }}
                                        </span>
                                    </td>

                                    {{-- Output Column Header (Editable) --}}
                                    <td class="px-3 py-3">
                                        <input
                                            type="text"
                                            wire:model.blur="fieldMappings.{{ $index }}.header"
                                            class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-950 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                                            placeholder="Enter column header..."
                                        >
                                    </td>

                                    {{-- Maps To (Dropdown) --}}
                                    <td class="px-3 py-3">
                                        <select
                                            wire:model.live="fieldMappings.{{ $index }}.field"
                                            class="block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-950 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                                        >
                                            <option value="">-- Select Field --</option>
                                            <optgroup label="Address Fields">
                                                <option value="name">Recipient Name</option>
                                                <option value="company">Company</option>
                                                <option value="external_reference">External Reference</option>
                                            </optgroup>
                                            <optgroup label="Original Address">
                                                <option value="original_address_line_1">Original Address Line 1</option>
                                                <option value="original_address_line_2">Original Address Line 2</option>
                                                <option value="original_city">Original City</option>
                                                <option value="original_state">Original State</option>
                                                <option value="original_postal_code">Original Postal Code</option>
                                            </optgroup>
                                            <optgroup label="Corrected Address (Recommended)">
                                                <option value="corrected_address_line_1">Corrected Address Line 1</option>
                                                <option value="corrected_address_line_2">Corrected Address Line 2</option>
                                                <option value="corrected_city">Corrected City</option>
                                                <option value="corrected_state">Corrected State</option>
                                                <option value="corrected_postal_code">Corrected Postal Code</option>
                                                <option value="corrected_postal_code_ext">Corrected ZIP+4</option>
                                                <option value="full_postal_code">Full Postal Code (with +4)</option>
                                                <option value="country_code">Country Code</option>
                                            </optgroup>
                                            <optgroup label="Validation Info">
                                                <option value="validation_status">Validation Status</option>
                                                <option value="is_residential">Is Residential</option>
                                                <option value="classification">Classification</option>
                                                <option value="confidence_score">Confidence Score</option>
                                                <option value="carrier">Carrier Used</option>
                                                <option value="validated_at">Validated At</option>
                                            </optgroup>
                                            <optgroup label="Extra Fields (Pass-Through)">
                                                @for($i = 1; $i <= 20; $i++)
                                                    <option value="extra_{{ $i }}">Extra Field {{ $i }}</option>
                                                @endfor
                                            </optgroup>
                                        </select>
                                    </td>

                                    {{-- Sample Data --}}
                                    @if(!empty($sampleAddresses))
                                        <td class="px-3 py-3">
                                            @if(!empty($mapping['field']))
                                                <span class="text-xs text-gray-600 dark:text-gray-400 italic">
                                                    {{ $this->getSampleValue(0, $mapping['field']) ?? 'No data' }}
                                                </span>
                                            @else
                                                <span class="text-xs text-gray-400 dark:text-gray-500">Select a field</span>
                                            @endif
                                        </td>
                                    @endif

                                    {{-- Actions --}}
                                    <td class="px-3 py-3">
                                        <div class="flex items-center justify-center gap-1">
                                            <button
                                                type="button"
                                                wire:click="moveColumnUp({{ $index }})"
                                                class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 disabled:opacity-30"
                                                @if($index === 0) disabled @endif
                                                title="Move Up"
                                            >
                                                <x-filament::icon icon="heroicon-o-chevron-up" class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="moveColumnDown({{ $index }})"
                                                class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 disabled:opacity-30"
                                                @if($index === count($fieldMappings) - 1) disabled @endif
                                                title="Move Down"
                                            >
                                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="removeColumn({{ $index }})"
                                                class="p-1.5 text-danger-400 hover:text-danger-600"
                                                title="Remove Column"
                                            >
                                                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ !empty($sampleAddresses) ? 5 : 4 }}" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No columns defined. Click "Add Column" to start building your export template.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex justify-start">
                    <x-filament::button
                        wire:click="addColumn"
                        icon="heroicon-o-plus"
                        color="gray"
                        size="sm"
                    >
                        Add Column
                    </x-filament::button>
                </div>
            </x-filament::section>

            {{-- Data Preview (if sample data available) --}}
            @if(!empty($sampleAddresses) && !empty($fieldMappings))
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Data Preview (First {{ count($sampleAddresses) }} rows)</x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    @foreach($fieldMappings as $mapping)
                                        @if(!empty($mapping['header']))
                                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 dark:text-gray-300">
                                                {{ $mapping['header'] }}
                                            </th>
                                        @endif
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($sampleAddresses as $addressIndex => $address)
                                    <tr>
                                        @foreach($fieldMappings as $mapping)
                                            @if(!empty($mapping['header']))
                                                <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400 truncate max-w-[150px]">
                                                    {{ $this->getSampleValue($addressIndex, $mapping['field'] ?? '') ?? '' }}
                                                </td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            {{-- Action Buttons --}}
            <div class="flex justify-between items-center pt-4">
                <x-filament::button
                    wire:click="backToSettings"
                    icon="heroicon-o-arrow-left"
                    color="gray"
                >
                    Back
                </x-filament::button>

                <x-filament::button
                    wire:click="saveTemplate"
                    icon="heroicon-o-check"
                    color="primary"
                >
                    Save Template
                </x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
