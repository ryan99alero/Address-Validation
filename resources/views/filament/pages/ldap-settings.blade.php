<x-filament-panels::page>
    @php
        $settings = \App\Models\LdapSetting::first();
    @endphp

    @if($settings && $settings->last_tested_at)
        <div class="mb-6">
            <x-filament::section>
                <x-slot name="heading">Connection Status</x-slot>

                <div class="flex items-center gap-4">
                    @if($settings->last_test_success)
                        <x-filament::badge color="success" size="lg">
                            Connected
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="danger" size="lg">
                            Connection Failed
                        </x-filament::badge>
                    @endif

                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Last tested: {{ $settings->last_tested_at->diffForHumans() }}
                    </span>
                </div>

                @if($settings->last_test_message)
                    <p class="mt-2 text-sm {{ $settings->last_test_success ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                        {{ $settings->last_test_message }}
                    </p>
                @endif
            </x-filament::section>
        </div>
    @endif

    <form wire:submit="saveSettings">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
