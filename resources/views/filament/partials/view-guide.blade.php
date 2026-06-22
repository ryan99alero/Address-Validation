@props(['guide'])

<x-filament::section collapsible collapsed icon="heroicon-o-information-circle">
    <x-slot name="heading">How to read this view</x-slot>
    <x-slot name="description">What each metric means and how it is calculated</x-slot>

    <div class="space-y-4 text-sm text-gray-600 dark:text-gray-400">
        <p>{{ $guide['intro'] }}</p>

        @if (! empty($guide['rule']))
            <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <span class="font-semibold">Pick the right lens —</span> {{ $guide['rule'] }}
            </div>
        @endif

        @if (! empty($guide['metrics']))
            <div>
                <h3 class="mb-2 font-semibold text-gray-700 dark:text-gray-300">Metrics ("Compare by")</h3>
                <div class="space-y-2">
                    @foreach ($guide['metrics'] as $m)
                        <div class="rounded-md bg-gray-50 p-3 dark:bg-white/5">
                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ $m['name'] }}</div>
                            <div>{{ $m['means'] }}</div>
                            <div class="font-mono text-xs text-primary-600 dark:text-primary-400">= {{ $m['formula'] }}</div>
                            <div class="italic">{{ $m['use'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach (['columns' => 'Columns', 'controls' => 'Filters &amp; controls'] as $key => $title)
            @if (! empty($guide[$key]))
                <div>
                    <h3 class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{!! $title !!}</h3>
                    <ul class="space-y-1">
                        @foreach ($guide[$key] as $item)
                            <li>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $item['name'] }}</span>
                                — {{ $item['means'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        <p class="text-xs text-gray-400 dark:text-gray-500">
            Full guide: <code>docs/carrier-fee-analytics-guide.md</code>
        </p>
    </div>
</x-filament::section>
