<x-filament-panels::page>
    {{-- Tabs Navigation - Using Filament's built-in tabs component --}}
    <x-filament::tabs label="Batch Processing Tabs">
        <x-filament::tabs.item
            :active="$activeTab === 'import'"
            wire:click="setActiveTab('import')"
            icon="heroicon-o-cloud-arrow-up"
        >
            Import
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'export'"
            wire:click="setActiveTab('export')"
            icon="heroicon-o-cloud-arrow-down"
        >
            Export
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Tab Content --}}
    <div style="margin-top: 24px;" wire:key="batch-tab-{{ $activeTab }}">
        {{-- ==================== IMPORT TAB ==================== --}}
        @if($activeTab === 'import')
            {{-- Step 1: Upload File --}}
            @if($this->importStep === 1)
                <form wire:submit="processUpload">
                    {{ $this->uploadForm }}

                    <div class="mt-6 flex items-center gap-4">
                        <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="processUpload">Upload & Continue</span>
                            <span wire:loading wire:target="processUpload">Processing...</span>
                        </x-filament::button>
                        <div wire:loading wire:target="processUpload" class="flex items-center gap-2 text-sm text-gray-500">
                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 animate-spin" />
                            <span>Parsing file, this may take a moment for large files...</span>
                        </div>
                    </div>
                </form>
            @endif

            {{-- Step 2: Field Mapping --}}
            @if($this->importStep === 2)
                <x-filament::section>
                    <x-slot name="heading">Map Fields</x-slot>
                    <x-slot name="description">
                        Map your file columns to the system fields. Fields highlighted in green are auto-matched.
                    </x-slot>

                    {{-- Template Selection --}}
                    <div class="mb-6 flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Load Template</label>
                            <select wire:model.live="selectedTemplateId" wire:change="loadTemplate" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
                                <option value="">-- Select Template --</option>
                                @foreach($this->getAvailableTemplates() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Save as New Template</label>
                            <div class="flex gap-2">
                                <input type="text" wire:model="newTemplateName" placeholder="Template name..." class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm">
                                <x-filament::button type="button" wire:click="saveAsTemplate" size="sm" color="gray">
                                    Save
                                </x-filament::button>
                            </div>
                        </div>
                    </div>

                    {{-- Field Mapping Table --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Position
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        File Column
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Maps To
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Sample Data
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach($this->mappings as $index => $mapping)
                                    <tr class="{{ $mapping['target'] ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            {{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($mapping['position'] + 1) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $mapping['source'] }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <select wire:model.live="mappings.{{ $index }}.target" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm text-sm">
                                                @foreach($this->getSystemFields() as $field => $label)
                                                    <option value="{{ $field }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">
                                            @if(isset($this->previewRows[0][$mapping['position']]))
                                                {{ $this->previewRows[0][$mapping['position']] }}
                                            @else
                                                <span class="italic">No data</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Preview Table --}}
                    @if(count($this->previewRows) > 0)
                        <div class="mt-6">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Data Preview (First {{ count($this->previewRows) }} rows)</h4>
                            <div class="overflow-x-auto bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                                <table class="min-w-full text-xs">
                                    <thead>
                                        <tr>
                                            @foreach($this->headers as $header)
                                                <th class="px-2 py-1 text-left font-medium text-gray-600 dark:text-gray-400">{{ $header }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->previewRows as $row)
                                            <tr>
                                                @foreach($row as $cell)
                                                    <td class="px-2 py-1 text-gray-700 dark:text-gray-300 truncate max-w-[150px]">{{ $cell }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </x-filament::section>

                <div class="mt-6 flex items-center gap-4">
                    <x-filament::button type="button" wire:click="startProcessing" icon="heroicon-o-play" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="startProcessing">Start Processing</span>
                        <span wire:loading wire:target="startProcessing">Importing...</span>
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="startNewImport" color="gray" icon="heroicon-o-arrow-left" wire:loading.attr="disabled" wire:target="startProcessing">
                        Back
                    </x-filament::button>
                    <div wire:loading wire:target="startProcessing" class="flex items-center gap-2 text-sm text-gray-500">
                        <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 animate-spin" />
                        <span>Importing {{ $this->batch?->total_rows ?? 0 }} addresses, please wait...</span>
                    </div>
                </div>
            @endif

            {{-- Step 3: Processing Complete --}}
            @if($this->importStep === 3)
                @php
                    $stillValidating = $this->batch &&
                        !$this->batch->isCancelled() &&
                        ($this->batch->validated_rows ?? 0) < ($this->batch->successful_rows ?? 0);
                @endphp
                <div @if($stillValidating) wire:poll.3s="refreshBatchProgress" @endif>
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center gap-2">
                            @if($this->batch?->isCancelled())
                                <x-filament::icon icon="heroicon-o-stop-circle" class="h-6 w-6 text-warning-500" />
                                <span class="text-warning-600 dark:text-warning-400">Validation Cancelled</span>
                            @elseif($this->batch?->isCompleted())
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-500" />
                                <span class="text-success-600 dark:text-success-400">Import Complete</span>
                            @elseif($this->batch?->isFailed())
                                <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6 text-danger-500" />
                                <span class="text-danger-600 dark:text-danger-400">Import Failed</span>
                            @else
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-6 w-6 text-primary-500 animate-spin" />
                                <span class="text-primary-600 dark:text-primary-400">Processing...</span>
                            @endif
                        </div>
                    </x-slot>

                    @if($this->batch)
                        {{-- Import Statistics --}}
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $this->batch->total_rows }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Total Rows</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-success-600">{{ $this->batch->successful_rows ?? 0 }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Imported</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-danger-600">{{ $this->batch->failed_rows ?? 0 }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Failed</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ ucfirst($this->batch->status) }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Status</p>
                            </div>
                        </div>

                        {{-- Validation Progress (if auto_validate was enabled) --}}
                        @if($this->batch->successful_rows > 0)
                            @php
                                $validatedRows = $this->batch->validated_rows ?? 0;
                                $totalToValidate = $this->batch->successful_rows;
                                $validationProgress = $totalToValidate > 0 ? round(($validatedRows / $totalToValidate) * 100) : 0;
                                $isValidating = $validatedRows < $totalToValidate && $validatedRows > 0;
                            @endphp

                            <div class="mt-6">
                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Validation Progress</h4>
                                <div class="bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                                    <div
                                        class="h-full transition-all duration-500 {{ $validationProgress >= 100 ? 'bg-success-500' : 'bg-primary-500' }}"
                                        style="width: {{ $validationProgress }}%"
                                    ></div>
                                </div>
                                <div class="mt-2 flex justify-between text-sm text-gray-600 dark:text-gray-400">
                                    <span>{{ $validatedRows }} / {{ $totalToValidate }} addresses validated</span>
                                    <span>{{ $validationProgress }}%</span>
                                </div>

                                @if($isValidating)
                                    <div class="mt-2 flex items-center justify-between">
                                        <div class="flex items-center gap-2 text-sm text-primary-600 dark:text-primary-400">
                                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4 animate-spin" />
                                            <span>Validation in progress...</span>
                                        </div>
                                        <x-filament::button
                                            type="button"
                                            wire:click="cancelValidation"
                                            color="danger"
                                            size="sm"
                                            icon="heroicon-o-stop"
                                        >
                                            Cancel
                                        </x-filament::button>
                                    </div>
                                @elseif($this->batch?->isCancelled())
                                    <div class="mt-2 flex items-center justify-between">
                                        <div class="flex items-center gap-2 text-sm text-warning-600 dark:text-warning-400">
                                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                                            <span>Validation cancelled ({{ $validatedRows }}/{{ $totalToValidate }} validated)</span>
                                        </div>
                                        @if($validatedRows < $totalToValidate)
                                            <x-filament::button
                                                type="button"
                                                wire:click="resumeValidation"
                                                color="primary"
                                                size="sm"
                                                icon="heroicon-o-play"
                                            >
                                                Resume
                                            </x-filament::button>
                                        @endif
                                    </div>
                                @elseif($validationProgress >= 100)
                                    <div class="mt-2 flex items-center gap-2 text-sm text-success-600 dark:text-success-400">
                                        <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                                        <span>Validation complete!</span>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($this->batch->isCompleted())
                            <div class="mt-6 p-4 bg-success-50 dark:bg-success-900/20 rounded-lg">
                                <p class="text-success-700 dark:text-success-300">
                                    Your addresses have been imported successfully.
                                    @if(($this->batch->validated_rows ?? 0) > 0)
                                        Validation is {{ ($this->batch->validated_rows ?? 0) >= ($this->batch->successful_rows ?? 0) ? 'complete' : 'in progress' }}.
                                    @endif
                                    Switch to the <strong>Export</strong> tab to download results.
                                </p>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-3">
                                <x-filament::button
                                    tag="a"
                                    href="{{ route('filament.admin.resources.addresses.index', ['tableFilters' => ['import_batch_id' => ['value' => $this->batch->id]]]) }}"
                                    color="gray"
                                    icon="heroicon-o-eye"
                                >
                                    View Addresses
                                </x-filament::button>

                                <x-filament::button
                                    type="button"
                                    wire:click="setActiveTab('export')"
                                    color="gray"
                                    icon="heroicon-o-arrow-down-tray"
                                >
                                    Go to Export
                                </x-filament::button>
                            </div>
                        @endif
                    @endif
                </x-filament::section>

                <div class="mt-6">
                    <x-filament::button type="button" wire:click="startNewImport" icon="heroicon-o-plus">
                        Start New Import
                    </x-filament::button>
                </div>
                </div>
            @endif
        @endif

        {{-- ==================== EXPORT TAB ==================== --}}
        @if($activeTab === 'export')
            {{-- Export Progress Banner - Always visible when export is running --}}
            @if($this->selectedExportBatch && in_array($this->selectedExportBatch->export_status, ['pending', 'processing']))
                <div class="mb-6 p-4 bg-primary-50 dark:bg-primary-900/30 border border-primary-200 dark:border-primary-800 rounded-lg" wire:poll.2s="refreshExportStatus">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-arrow-path" class="h-6 w-6 animate-spin text-primary-600" />
                        <div>
                            <p class="font-medium text-primary-700 dark:text-primary-300">
                                Export in progress: {{ $this->selectedExportBatch->display_name }}
                            </p>
                            <p class="text-sm text-primary-600 dark:text-primary-400">
                                Processing {{ number_format($this->totalAddresses) }} addresses. This may take several minutes for large batches...
                            </p>
                        </div>
                    </div>
                </div>
            @elseif($this->selectedExportBatch && $this->selectedExportBatch->export_status === 'completed')
                <div class="mb-6 p-4 bg-success-50 dark:bg-success-900/30 border border-success-200 dark:border-success-800 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-600" />
                            <div>
                                <p class="font-medium text-success-700 dark:text-success-300">
                                    Export ready: {{ $this->selectedExportBatch->display_name }}
                                </p>
                                <p class="text-sm text-success-600 dark:text-success-400">
                                    Generated {{ $this->selectedExportBatch->export_completed_at?->diffForHumans() }}
                                </p>
                            </div>
                        </div>
                        <x-filament::button
                            tag="a"
                            href="{{ route('filament.admin.pages.batch-processing.download', $this->selectedExportBatch->id) }}"
                            icon="heroicon-o-arrow-down-tray"
                            color="success"
                            size="lg"
                        >
                            Download Export
                        </x-filament::button>
                    </div>
                </div>
            @elseif($this->selectedExportBatch && $this->selectedExportBatch->export_status === 'failed')
                <div class="mb-6 p-4 bg-danger-50 dark:bg-danger-900/30 border border-danger-200 dark:border-danger-800 rounded-lg">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-x-circle" class="h-6 w-6 text-danger-600" />
                        <div>
                            <p class="font-medium text-danger-700 dark:text-danger-300">
                                Export failed: {{ $this->selectedExportBatch->display_name }}
                            </p>
                            <p class="text-sm text-danger-600 dark:text-danger-400">
                                Please check the logs and try again.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <form wire:submit="startExport">
                {{ $this->exportForm }}

                {{-- Batch Statistics --}}
                @if($this->selectedExportBatch)
                    <x-filament::section class="mt-6">
                        <x-slot name="heading">Batch Statistics</x-slot>
                        <x-slot name="description">
                            {{ $this->selectedExportBatch->display_name }}
                        </x-slot>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $this->totalAddresses }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Total Addresses</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-primary-600">{{ $this->validatedAddresses }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Validated</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-success-600">{{ $this->validAddresses }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Valid</p>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 text-center">
                                <p class="text-2xl font-bold text-danger-600">{{ $this->invalidAddresses }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Invalid</p>
                            </div>
                        </div>

                        @if($this->selectedExportBatch->carrier)
                            <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <span class="font-medium">Carrier:</span> {{ $this->selectedExportBatch->carrier->name }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <span class="font-medium">Imported:</span> {{ $this->selectedExportBatch->created_at->format('M d, Y g:i A') }}
                                </p>
                            </div>
                        @endif

                        {{-- Export Status Section --}}
                        @if($this->selectedExportBatch->export_status)
                            <div class="mt-4 p-4 rounded-lg {{ match($this->selectedExportBatch->export_status) {
                                'completed' => 'bg-success-50 dark:bg-success-900/20',
                                'processing', 'pending' => 'bg-primary-50 dark:bg-primary-900/20',
                                'failed' => 'bg-danger-50 dark:bg-danger-900/20',
                                default => 'bg-gray-50 dark:bg-gray-800'
                            } }}" @if(in_array($this->selectedExportBatch->export_status, ['pending', 'processing'])) wire:poll.2s="refreshExportStatus" @endif>
                                @if($this->selectedExportBatch->export_status === 'processing' || $this->selectedExportBatch->export_status === 'pending')
                                    <div class="flex items-center gap-3">
                                        <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 animate-spin text-primary-600" />
                                        <span class="text-primary-700 dark:text-primary-300 font-medium">
                                            Export in progress... This may take a few minutes for large batches.
                                        </span>
                                    </div>
                                @elseif($this->selectedExportBatch->export_status === 'completed')
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600" />
                                            <span class="text-success-700 dark:text-success-300 font-medium">
                                                Export ready! Click the button to download.
                                            </span>
                                        </div>
                                        <x-filament::button
                                            tag="a"
                                            href="{{ route('filament.admin.pages.batch-processing.download', $this->selectedExportBatch->id) }}"
                                            icon="heroicon-o-arrow-down-tray"
                                            color="success"
                                        >
                                            Download
                                        </x-filament::button>
                                    </div>
                                    @if($this->selectedExportBatch->export_completed_at)
                                        <p class="mt-2 text-sm text-gray-500">
                                            Generated {{ $this->selectedExportBatch->export_completed_at->diffForHumans() }}
                                        </p>
                                    @endif
                                @elseif($this->selectedExportBatch->export_status === 'failed')
                                    <div class="flex items-center gap-3">
                                        <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-danger-600" />
                                        <span class="text-danger-700 dark:text-danger-300 font-medium">
                                            Export failed. Please try again.
                                        </span>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </x-filament::section>
                @endif

                <div class="mt-6">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">
                        Generate Export
                    </x-filament::button>
                </div>
            </form>

            {{-- Export Templates Info --}}
            <x-filament::section class="mt-8">
                <x-slot name="heading">Export Templates</x-slot>
                <x-slot name="description">
                    Create custom export templates for different shipping systems like ePace, UPS WorldShip, or FedEx.
                </x-slot>

                <div class="prose dark:prose-invert max-w-none text-sm">
                    <p>Export templates define:</p>
                    <ul class="list-disc list-inside space-y-1 text-gray-600 dark:text-gray-400">
                        <li><strong>Field Layout</strong> - Which fields to include and their order</li>
                        <li><strong>Headers</strong> - Custom column headers for each field</li>
                        <li><strong>File Format</strong> - CSV, Excel (XLSX), or Fixed Width</li>
                        <li><strong>Target System</strong> - ePace, UPS WorldShip, FedEx Ship Manager, etc.</li>
                    </ul>
                </div>

                <div class="mt-4 flex gap-3">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.export-templates.create') }}"
                        color="primary"
                        icon="heroicon-o-plus"
                    >
                        Create Template
                    </x-filament::button>
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.export-templates.index') }}"
                        color="gray"
                        icon="heroicon-o-cog-6-tooth"
                    >
                        Manage Templates
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
