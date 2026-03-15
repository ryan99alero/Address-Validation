<x-filament-panels::page>
    @php
        $pendingCount = $this->getPendingRequestsCount();
    @endphp

    {{-- Pending Requests Badge --}}
    @if($pendingCount > 0 && $activeTab !== 'requests')
        <div style="margin-bottom: 16px;">
            <x-filament::section>
                <div style="display: flex; align-items: center; gap: 12px; background-color: rgba(251, 191, 36, 0.1); padding: 12px 16px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                    <x-heroicon-o-clock style="width: 24px; height: 24px; color: #f59e0b; flex-shrink: 0;" />
                    <div>
                        <p style="font-weight: 600; color: #b45309;">{{ $pendingCount }} Pending Request(s)</p>
                        <p style="font-size: 0.875rem; color: #92400e;">
                            You have vacation requests awaiting your review.
                            <button wire:click="setActiveTab('requests')" style="font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: none; color: #92400e;">
                                View Requests
                            </button>
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- Tabs Navigation --}}
    <x-filament::tabs label="Vacation Management Tabs">
        <x-filament::tabs.item
            :active="$activeTab === 'requests'"
            wire:click="setActiveTab('requests')"
            icon="heroicon-o-inbox"
        >
            Requests
            @if($pendingCount > 0)
                <x-slot name="badge">
                    {{ $pendingCount }}
                </x-slot>
            @endif
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'calendar'"
            wire:click="setActiveTab('calendar')"
            icon="heroicon-o-calendar"
        >
            Calendar
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'balances'"
            wire:click="setActiveTab('balances')"
            icon="heroicon-o-calculator"
        >
            Balances
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'policies'"
            wire:click="setActiveTab('policies')"
            icon="heroicon-o-document-text"
        >
            Policies
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'processing'"
            wire:click="setActiveTab('processing')"
            icon="heroicon-o-cog-6-tooth"
        >
            Processing
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Tab Content --}}
    <div style="margin-top: 24px;" wire:key="vacation-tab-{{ $activeTab }}">
        @if($activeTab === 'requests')
            <x-filament::section>
                <x-slot name="heading">Vacation Requests</x-slot>
                <x-slot name="description">Review and manage employee vacation requests</x-slot>

                <div wire:key="table-requests">
                    {{ $this->table }}
                </div>
            </x-filament::section>
        @elseif($activeTab === 'calendar')
            <x-filament::section>
                <x-slot name="heading">Vacation Calendar</x-slot>
                <x-slot name="description">Approved vacation entries by date</x-slot>

                <div wire:key="table-calendar">
                    {{ $this->table }}
                </div>
            </x-filament::section>
        @elseif($activeTab === 'balances')
            <x-filament::section>
                <x-slot name="heading">Vacation Balances</x-slot>
                <x-slot name="description">Employee vacation hour balances</x-slot>

                <div wire:key="table-balances">
                    {{ $this->table }}
                </div>
            </x-filament::section>
        @elseif($activeTab === 'policies')
            <x-filament::section>
                <x-slot name="heading">Vacation Policies</x-slot>
                <x-slot name="description">Vacation accrual policy tiers based on tenure</x-slot>

                <div wire:key="table-policies">
                    {{ $this->table }}
                </div>
            </x-filament::section>
        @elseif($activeTab === 'processing')
            <x-filament::section>
                <x-slot name="heading">Vacation Processing</x-slot>
                <x-slot name="description">Manual vacation accrual processing and transaction history</x-slot>

                <div wire:key="table-processing">
                    {{ $this->table }}
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
