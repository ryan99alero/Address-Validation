<?php

use App\Filament\Resources\CarrierCharges\CarrierChargeResource;   // Adjustments — non-admin accessible
use App\Filament\Resources\CarrierInvoices\CarrierInvoiceResource;
use App\Filament\Resources\Carriers\CarrierResource;               // Integrations — admin only
use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\RecentItem;
use App\Models\User;
use App\Support\RecentItems;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRecent(User $user, array $overrides = []): RecentItem
{
    return RecentItem::create(array_merge([
        'user_id' => $user->id,
        'type' => 'page',
        'route_name' => 'seed.route',
        'record_key' => '',
        'filament_class' => CarrierChargeResource::class,
        'label' => 'Seed',
        'url' => 'http://localhost/seed',
        'visit_count' => 1,
        'visited_at' => now(),
    ], $overrides));
}

test('visiting a resource index records a recent item with a denormalized label', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $this->get(CarrierChargeResource::getUrl('index'))->assertOk();

    $item = RecentItem::where('user_id', $user->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->type)->toBe('page')
        ->and($item->filament_class)->toBe(CarrierChargeResource::class)
        ->and($item->label)->toBe('Adjustments')
        ->and($item->visit_count)->toBe(1);
});

test('revisiting increments visit_count without duplicating the row', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $this->get(CarrierChargeResource::getUrl('index'))->assertOk();
    $this->get(CarrierChargeResource::getUrl('index'))->assertOk();

    expect(RecentItem::where('user_id', $user->id)->count())->toBe(1)
        ->and(RecentItem::where('user_id', $user->id)->first()->visit_count)->toBe(2);
});

test('recents are pruned to the newest 30 per user', function () {
    $user = User::factory()->create(['is_admin' => true]);
    foreach (range(1, 30) as $i) {
        seedRecent($user, ['route_name' => "seed.$i", 'label' => "Seed $i", 'visited_at' => now()->subDays(31 - $i)]);
    }
    $this->actingAs($user);

    $this->get(CarrierChargeResource::getUrl('index'))->assertOk(); // 31st distinct → prune

    expect(RecentItem::where('user_id', $user->id)->count())->toBe(30)
        ->and(RecentItem::where('user_id', $user->id)->where('route_name', 'seed.1')->exists())->toBeFalse();
});

test('forUser filters out items the user can no longer access', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $this->actingAs($user); // canAccess() reads the globally authenticated user

    seedRecent($user, ['route_name' => 'ok', 'filament_class' => CarrierChargeResource::class]);
    seedRecent($user, ['route_name' => 'gated', 'filament_class' => CarrierResource::class]); // admin-only

    $recents = app(RecentItems::class)->forUser($user, 7);

    expect($recents)->toHaveCount(1)
        ->and($recents->first()->filament_class)->toBe(CarrierChargeResource::class);
});

test('the recent redirector fails soft on a since-deleted record', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $item = seedRecent($user, [
        'type' => 'record', 'record_key' => '99999',
        'filament_class' => CarrierInvoiceResource::class, 'label' => 'Invoice: GONE',
        'url' => 'http://localhost/carrier-invoices/99999',
    ]);

    $this->get(route('recent.go', $item))->assertRedirect(CarrierInvoiceResource::getUrl('index'));
    expect(RecentItem::find($item->id))->toBeNull();
});

test('the recent redirector forwards to the stored url for a live record', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);
    $carrier = Carrier::factory()->create();
    $invoice = CarrierInvoice::create(['carrier_id' => $carrier->id, 'invoice_number' => 'INV-R', 'invoice_date' => now()->toDateString()]);

    $item = seedRecent($user, [
        'type' => 'record', 'record_key' => (string) $invoice->id,
        'filament_class' => CarrierInvoiceResource::class, 'label' => 'Invoice: INV-R',
        'url' => CarrierInvoiceResource::getUrl('view', ['record' => $invoice]),
    ]);

    $this->get(route('recent.go', $item))->assertRedirect($item->url);
    expect(RecentItem::find($item->id))->not->toBeNull();
});

test('a guest hitting the login page records nothing', function () {
    $this->get('/login')->assertOk();

    expect(RecentItem::count())->toBe(0);
});
