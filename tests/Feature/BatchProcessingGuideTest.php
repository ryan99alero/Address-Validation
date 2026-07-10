<?php

use App\Filament\Pages\BatchProcessing;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the batch page with the collapsible how-it-works guide', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(BatchProcessing::class)
        ->assertOk()
        ->assertSee('How Batch Processing works')
        ->assertSee('Include service / transit results')
        ->assertSee('Reverse scheduling');
});

it('offers only single-carrier validation engines (no FedEx/UPS chains)', function () {
    Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx', 'is_active' => true]);
    Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS', 'is_active' => true]);

    $options = (new BatchProcessing)->validationEngineOptions();

    expect($options)->toBe(['fedex' => 'FedEx', 'ups' => 'UPS'])
        ->and($options)->not->toHaveKey('fedex_ups')
        ->and($options)->not->toHaveKey('ups_fedex');
});

it('exposes a well-formed guide structure', function () {
    $guide = (new BatchProcessing)->viewGuide();

    expect($guide)->toHaveKeys(['heading', 'intro', 'rule', 'sections'])
        ->and($guide['rule'])->toHaveKeys(['label', 'text'])
        ->and($guide['sections'])->not->toBeEmpty();

    foreach ($guide['sections'] as $section) {
        expect($section)->toHaveKeys(['title', 'items'])
            ->and($section['items'])->not->toBeEmpty();
        foreach ($section['items'] as $item) {
            expect($item)->toHaveKeys(['name', 'means']);
        }
    }
});
