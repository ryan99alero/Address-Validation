<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('filament.admin.pages.dashboard'));
    $response->assertRedirect(route('filament.admin.auth.login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('filament.admin.pages.dashboard'));
    $response->assertOk()
        ->assertSee('Timeline')     // the Dashboard / Timeline filter label renders
        ->assertSee('Full year');   // the month select's default option
});
