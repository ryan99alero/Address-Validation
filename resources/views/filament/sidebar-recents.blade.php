@php
    $recents = auth()->check()
        ? app(\App\Support\RecentItems::class)->forUser(auth()->user(), 5)
        : collect();
@endphp

@if ($recents->isNotEmpty())
    {{-- Additive "Recent" list at the top of the sidebar nav (SIDEBAR_NAV_START hook). Each link
         goes through the /recent redirector so a since-deleted record fails soft. --}}
    <div class="mb-2 px-2">
        <div class="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Recent
        </div>
        <ul class="flex flex-col gap-0.5">
            @foreach ($recents as $item)
                <li>
                    <a
                        href="{{ route('recent.go', $item) }}"
                        @class([
                            'flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm outline-none transition',
                            'text-gray-700 hover:bg-gray-100 focus:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5 dark:focus:bg-white/5',
                        ])
                    >
                        <x-filament::icon
                            :icon="$item->type === \App\Models\RecentItem::TYPE_RECORD ? 'heroicon-o-document' : 'heroicon-o-clock'"
                            class="h-4 w-4 shrink-0 text-gray-400"
                        />
                        <span class="truncate">{{ $item->label }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
