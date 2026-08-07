@php
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $stats = $this->getCachedStats();

    // Filament semantic colour name -> description text classes for the enlarged cards. Written as
    // full literal class strings so Tailwind's content scan compiles them.
    $descriptionColorClasses = [
        'primary' => 'text-primary-600 dark:text-primary-400',
        'success' => 'text-success-600 dark:text-success-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'info' => 'text-info-600 dark:text-info-400',
        'gray' => 'text-gray-500 dark:text-gray-400',
    ];
@endphp

<x-filament-widgets::widget class="fi-wi-stats-overview">
    <div x-data="{ open: false }">
        {{-- Tile: the normal Filament stats grid. A click overlay sits on top so clicking anywhere
             opens the enlarged copy, matching the charts and maps. --}}
        <div class="relative">
            {{ $this->content }}

            <div
                @click="open = true"
                title="Click to expand"
                class="absolute inset-0 cursor-pointer rounded-xl ring-1 ring-transparent transition hover:ring-2 hover:ring-primary-500"
            >
                <div class="absolute right-2 top-2 rounded-md bg-gray-100 p-1 text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                    <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4" />
                </div>
            </div>
        </div>

        {{-- Enlarged copy — the same stats rendered as larger cards. --}}
        <div
            x-show="open"
            @keydown.escape.window="open = false"
            @click.self="open = false"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            style="display: none; background-color: rgba(17, 24, 39, 0.75);"
        >
            <div class="relative max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
                        @if ($description)
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        @click="open = false"
                        class="rounded-md p-1 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($stats as $stat)
                        @php
                            $descColor = $descriptionColorClasses[$stat->getColor() ?? 'gray'] ?? $descriptionColorClasses['gray'];
                            $descIcon = $stat->getDescriptionIcon();
                        @endphp
                        <div class="rounded-xl bg-gray-50 p-6 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $stat->getLabel() }}</div>
                            <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $stat->getValue() }}</div>
                            @if ($stat->getDescription())
                                <div class="mt-2 flex items-center gap-1 text-sm {{ $descColor }}">
                                    @if ($descIcon)
                                        <x-filament::icon :icon="$descIcon" class="h-4 w-4" />
                                    @endif
                                    <span>{{ $stat->getDescription() }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
