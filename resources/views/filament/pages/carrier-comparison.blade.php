<x-filament-panels::page>
    @include('filament.partials.view-guide', ['guide' => $this->viewGuide()])

    {{ $this->table }}
</x-filament-panels::page>
