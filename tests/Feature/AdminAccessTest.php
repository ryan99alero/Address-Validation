<?php

use App\Filament\Pages\CompanySetup;
use App\Filament\Resources\FolderIntegrations\FolderIntegrationResource;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Filament\Resources\MailIntegrations\MailIntegrationResource;
use App\Filament\Resources\PaceCorrections\PaceCorrectionResource;
use App\Filament\Resources\ShipViaCodes\ShipViaCodeResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$adminOnly = [
    IntegrationConnectionResource::class,
    MailIntegrationResource::class,
    FolderIntegrationResource::class,
    CompanySetup::class,
    ShipViaCodeResource::class,
];

it('hides admin-only config from non-admins', function (string $resource) {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    expect($resource::canAccess())->toBeFalse();
})->with($adminOnly);

it('grants admin-only config to admins', function (string $resource) {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    expect($resource::canAccess())->toBeTrue();
})->with($adminOnly);

it('keeps Pace Corrections visible to non-admins', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    expect(PaceCorrectionResource::canAccess())->toBeTrue();
});
