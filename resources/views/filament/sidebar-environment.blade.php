@php
    $env = app()->environment();
    $isProd = $env === 'production';
    $host = gethostname() ?: 'unknown-host';
    $user = auth()->user();
    $version = trim((string) config('app.version', '')) ?: null;
@endphp

{{-- Pinned to the sidebar bottom (SIDEBAR_FOOTER render hook). Amber when NOT production so a
     staging/local session is unmistakable at a glance; neutral with a green dot on production.
     Additive content only — no Filament component is restyled. --}}
<div
    @class([
        'mx-2 mb-2 overflow-hidden rounded-lg px-3 py-2 text-xs leading-tight ring-1',
        'bg-gray-50 text-gray-600 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10' => $isProd,
        'bg-amber-100 text-amber-900 ring-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-400/30' => ! $isProd,
    ])
    title="{{ strtoupper($env) }} · {{ $host }}{{ $version ? ' · '.$version : '' }}"
>
    <div class="flex items-center gap-1.5 font-semibold uppercase tracking-wide">
        <span @class([
            'inline-block h-2 w-2 shrink-0 rounded-full',
            'bg-emerald-500' => $isProd,
            'bg-amber-500' => ! $isProd,
        ])></span>
        <span class="truncate">{{ $isProd ? 'Production' : $env }}</span>
    </div>
    <div class="mt-0.5 truncate opacity-80">{{ $host }}</div>
    @if ($user)
        <div class="truncate opacity-80">{{ $user->email ?? $user->name }}</div>
    @endif
    @if ($version)
        <div class="truncate opacity-60">{{ $version }}</div>
    @endif
</div>
