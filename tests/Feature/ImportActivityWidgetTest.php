<?php

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\QueueStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the dashboard timeline row shows the queue status', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('Processing now')
        ->assertSee('Queued')
        ->assertSee('Failed');
});

test('QueueStatus reports integer counts', function () {
    $counts = QueueStatus::counts();

    expect($counts)->toHaveKeys(['processing', 'queued', 'failed'])
        ->and($counts['processing'])->toBeInt()
        ->and($counts['queued'])->toBeInt()
        ->and($counts['failed'])->toBeInt();
});
