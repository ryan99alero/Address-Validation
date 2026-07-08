<?php

use App\Filament\Resources\Carriers\CarrierResource;
use App\Filament\Resources\ChargeCategories\ChargeCategoryResource;
use App\Filament\Resources\ExportTemplates\ExportTemplateResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('clustered resources live under their cluster URL prefix', function () {
    expect(CarrierResource::getUrl('index'))->toContain('/integrations/carriers');
    expect(ChargeCategoryResource::getUrl('index'))->toContain('/charge-classification/charge-categories');
    expect(ExportTemplateResource::getUrl('index'))->toContain('/templates/export-templates');
});

test('legacy resource URLs redirect to the new cluster locations', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/carriers')->assertRedirect(CarrierResource::getUrl('index'));
    $this->get('/charge-categories')->assertRedirect(ChargeCategoryResource::getUrl('index'));
    $this->get('/export-templates')->assertRedirect(ExportTemplateResource::getUrl('index'));
    $this->get('/carriers/create')->assertRedirect('/integrations/carriers/create'); // deep sub-path
});

test('admin sees cluster nav items and the cluster sub-navigation tabs', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(CarrierResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Integrations')            // cluster nav item / breadcrumb
        ->assertSee('Charge Classification')   // sibling cluster in the sidebar
        ->assertSee('Templates')               // sibling cluster in the sidebar
        ->assertSee('API Integrations')        // Integrations sub-nav tab
        ->assertSee('SQL Connections');        // Integrations sub-nav tab
});

test('the integrations cluster is admin-only', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/integrations/carriers')->assertForbidden();
});
