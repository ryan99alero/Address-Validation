@props(['guide'])

<x-filament::section collapsible collapsed icon="heroicon-o-information-circle">
    <x-slot name="heading">{{ $guide['heading'] ?? 'How this works' }}</x-slot>
    @if (! empty($guide['description']))
        <x-slot name="description">{{ $guide['description'] }}</x-slot>
    @endif

    <div class="space-y-4 text-sm text-gray-600 dark:text-gray-400">
        @if (! empty($guide['intro']))
            <p>{{ $guide['intro'] }}</p>
        @endif

        @if (! empty($guide['rule']))
            <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <span class="font-semibold">{{ $guide['rule']['label'] ?? 'Important' }} —</span> {{ $guide['rule']['text'] ?? $guide['rule'] }}
            </div>
        @endif

        @foreach ($guide['sections'] ?? [] as $section)
            <div>
                <h3 class="mb-2 font-semibold text-gray-700 dark:text-gray-300">{{ $section['title'] }}</h3>
                <div class="space-y-2">
                    @foreach ($section['items'] as $item)
                        <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                            <div>{{ $item['means'] }}</div>
                            @if (! empty($item['how']))
                                <div class="font-mono text-xs text-primary-600 dark:text-primary-400">{{ $item['how'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if (! empty($guide['note']))
            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $guide['note'] }}</p>
        @endif
    </div>
</x-filament::section>
