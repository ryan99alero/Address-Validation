<x-filament-panels::page>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main Form --}}
        <div class="lg:col-span-2">
            <form wire:submit="save">
                {{ $this->form }}

                <div class="fi-form-actions mt-6">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        Save Settings
                    </x-filament::button>
                </div>
            </form>
        </div>

        {{-- Active Users Sidebar --}}
        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-users" class="h-5 w-5 text-success-500" />
                        Active Users
                    </div>
                </x-slot>

                <x-slot name="description">
                    Users currently logged in
                </x-slot>

                @php
                    $activeUsers = $this->getActiveUsers();
                @endphp

                @if($activeUsers->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No active users</p>
                @else
                    <ul class="space-y-3">
                        @foreach($activeUsers as $user)
                            <li class="flex items-center justify-between p-2 rounded-lg bg-gray-50 dark:bg-gray-800">
                                <div class="flex items-center gap-3">
                                    <div class="h-2 w-2 rounded-full bg-success-500"></div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $user->name }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $user->email }}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $user->last_active }}
                                    </p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $user->ip_address }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Session lifetime: {{ config('session.lifetime') }} minutes
                    </p>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
