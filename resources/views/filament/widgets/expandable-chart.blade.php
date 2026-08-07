@php
    use Filament\Support\Facades\FilamentAsset;

    $heading = $this->getHeading();
    $description = $this->getDescription();
    $type = $this->getType();
    $cachedData = $this->getCachedData();
    $options = $this->getOptions();
    $maxHeight = $this->getMaxHeight();
    $chartSrc = FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets');
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div x-data="{ open: false }">
            {{-- Tile: the live Filament chart. A click overlay sits on top so the tile itself is a
                 non-interactive preview and clicking anywhere opens the large, interactive copy. --}}
            <div class="relative">
                <div
                    x-load
                    x-load-src="{{ $chartSrc }}"
                    wire:ignore
                    data-chart-type="{{ $type }}"
                    x-data="chart({ cachedData: @js($cachedData), maxHeight: @js($maxHeight), options: @js($options), type: @js($type) })"
                    @class([
                        'fi-wi-chart-canvas-ctn',
                        'fi-wi-chart-canvas-ctn-no-aspect-ratio' => filled($maxHeight),
                    ])
                >
                    <canvas x-ref="canvas" @if ($maxHeight) style="max-height: {{ $maxHeight }}" @endif></canvas>
                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>

                <div
                    @click="open = true"
                    title="Click to expand"
                    class="absolute inset-0 cursor-pointer rounded-lg ring-1 ring-transparent transition hover:ring-2 hover:ring-primary-500"
                >
                    <div class="absolute right-2 top-2 rounded-md bg-gray-100 p-1 text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                        <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4" />
                    </div>
                </div>
            </div>

            {{-- Enlarged, interactive copy — only rendered while open so Chart.js sizes to the modal. --}}
            <div
                x-show="open"
                @keydown.escape.window="open = false"
                @click.self="open = false"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                style="display: none; background-color: rgba(17, 24, 39, 0.75);"
            >
                <div class="relative w-full max-w-6xl rounded-xl bg-white p-4 shadow-2xl dark:bg-gray-900">
                    <div class="mb-2 flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
                            @if ($description)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
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

                    <template x-if="open">
                        <div
                            x-load
                            x-load-src="{{ $chartSrc }}"
                            data-chart-type="{{ $type }}"
                            x-data="chart({ cachedData: @js($cachedData), maxHeight: '72vh', options: @js($options), type: @js($type) })"
                            class="fi-wi-chart-canvas-ctn fi-wi-chart-canvas-ctn-no-aspect-ratio"
                        >
                            <canvas x-ref="canvas" style="max-height: 72vh"></canvas>
                            <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                            <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                            <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                            <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
