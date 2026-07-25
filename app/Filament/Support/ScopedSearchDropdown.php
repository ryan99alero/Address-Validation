<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * The "Search in: [ All fields ▾ ]" selector rendered inline right after a table's search box (via
 * the TOOLBAR_SEARCH_AFTER render hook). Only appears on table components that use the
 * ScopedTableSearch trait — it binds to that component's public $tableSearchColumn property.
 */
class ScopedSearchDropdown
{
    public static function render(): string
    {
        $livewire = Livewire::current();
        if (! $livewire || ! method_exists($livewire, 'getSearchScopeOptions')) {
            return '';
        }

        $options = $livewire->getSearchScopeOptions();
        if (count($options) <= 1) {
            return ''; // nothing scopeable
        }

        return Blade::render(<<<'BLADE'
            <x-filament::input.wrapper class="fi-ta-search-scope shrink-0" style="max-width: 12rem">
                <x-filament::input.select wire:model.live="tableSearchColumn" aria-label="Restrict search to a field">
                    @foreach ($options as $value => $label)
                        <option value="{{ $value }}">{{ $value === '' ? $label : 'Only: '.$label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        BLADE, ['options' => $options]);
    }
}
