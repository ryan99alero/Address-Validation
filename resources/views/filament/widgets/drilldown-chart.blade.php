@php
    use Filament\Support\Facades\FilamentAsset;

    $heading = $this->getHeading();
    $description = $this->getDescription();
    $type = $this->getType();
    $cachedData = $this->getCachedData();
    $options = $this->getOptions();
    $maxHeight = $this->getMaxHeight();
    $chartSrc = FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets');

    // Bar-click drill: hit-test the click against the Chart.js instance (reached via the Filament
    // chart component's in-scope getChart(), so no global Chart class is needed) and drill into the
    // clicked label. No-op once already drilled in.
    $drillClick = <<<'JS'
        if ($wire.drillCategory) return;
        if (typeof getChart !== 'function') return;
        const c = getChart();
        if (! c) return;
        const els = c.getElementsAtEventForMode($event, 'nearest', { intersect: true }, true);
        if (els.length) { $wire.call('drillIntoCategory', c.data.labels[els[0].index]); }
    JS;
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div x-data="{ open: false }">
            {{-- Tile: the live Filament chart. Bar clicks drill down; Back / Expand controls sit in
                 the corners (not a full overlay) so they don't swallow bar clicks. --}}
            <div class="relative">
                @if ($this->drillCategory)
                    <button
                        type="button"
                        wire:click="clearDrill"
                        class="fi-btn absolute start-2 top-2 z-10 flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        <x-filament::icon icon="heroicon-m-arrow-left" class="h-3.5 w-3.5" />
                        Back
                    </button>
                @endif

                <div
                    x-load
                    x-load-src="{{ $chartSrc }}"
                    wire:ignore
                    data-chart-type="{{ $type }}"
                    x-data="chart({ cachedData: @js($cachedData), maxHeight: @js($maxHeight), options: @js($options), type: @js($type) })"
                    x-bind:class="{ 'cursor-pointer': ! $wire.drillCategory }"
                    @click="{{ $drillClick }}"
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

                <button
                    type="button"
                    @click="open = true"
                    title="Click to expand"
                    class="absolute end-2 top-2 z-10 rounded-md bg-gray-100 p-1 text-gray-700 shadow-sm transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4" />
                </button>
            </div>

            {{-- Enlarged, interactive copy — only rendered while open so Chart.js sizes to the modal.
                 Bars stay clickable here too, and Back is in the modal header. --}}
            <div
                x-show="open"
                @keydown.escape.window="open = false"
                @click.self="open = false"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                style="display: none; background-color: rgba(17, 24, 39, 0.75);"
            >
                <div class="relative w-full max-w-6xl rounded-xl bg-white p-4 shadow-2xl dark:bg-gray-900">
                    <div class="mb-2 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            @if ($this->drillCategory)
                                <button
                                    type="button"
                                    wire:click="clearDrill"
                                    class="flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    <x-filament::icon icon="heroicon-m-arrow-left" class="h-3.5 w-3.5" />
                                    Back
                                </button>
                            @endif
                            <div>
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $heading }}</h3>
                                @if ($description)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
                                @endif
                            </div>
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
                            x-bind:class="{ 'cursor-pointer': ! $wire.drillCategory }"
                            @click="{{ $drillClick }}"
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
