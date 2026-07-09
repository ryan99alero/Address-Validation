<?php

use App\Filament\Pages\BatchProcessing;
use App\Filament\Resources\ExportTemplates\ExportTemplateResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Regression: the batch Export tab links to the export-template create/index routes. Clustering
 * ExportTemplates renamed those routes, so a hardcoded route('filament.admin.resources.export-
 * templates.*') threw RouteNotFoundException and froze the export UI. getUrl() resolves the cluster
 * route instead.
 */
it('renders the export tab (which links to the clustered export-template routes)', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(BatchProcessing::class)
        ->set('activeTab', 'export')
        ->assertOk()
        ->assertSee('Create Template'); // the button whose href previously threw
});

it('resolves the clustered export-template URLs', function () {
    expect(ExportTemplateResource::getUrl('create'))->toContain('/templates/export-templates/create')
        ->and(ExportTemplateResource::getUrl('index'))->toContain('/templates/export-templates');
});
