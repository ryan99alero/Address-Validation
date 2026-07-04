<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="fi-form-actions mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Save Settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
