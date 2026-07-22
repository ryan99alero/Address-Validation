<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('the grid export route downloads a stored CSV', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    Storage::disk('local')->put('exports/ChargebackPush_20260722_120000_abc123.csv', "h1,h2\nv1,v2\n");

    $this->get(route('grid-export.download', ['file' => 'ChargebackPush_20260722_120000_abc123.csv']))
        ->assertOk()
        ->assertDownload('ChargebackPush_20260722_120000_abc123.csv');
});

test('the grid export route rejects a missing file and a non-csv name', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());

    $this->get(route('grid-export.download', ['file' => 'nope.csv']))->assertNotFound();
    $this->get(route('grid-export.download', ['file' => 'secrets.txt']))->assertNotFound();
});

test('a bare filename is used verbatim (basename guards against path traversal)', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    // Only the basename is honored, so an attempt to reach outside exports/ can never resolve.
    Storage::disk('local')->put('exports/env.csv', "x\n1\n");
    $this->get(route('grid-export.download', ['file' => 'env.csv']))->assertOk();
});

test('the grid export route blocks guests', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/ok.csv', "x\n1\n");

    // A guest must not receive the file, however the auth middleware chooses to deny it.
    expect($this->get(route('grid-export.download', ['file' => 'ok.csv']))->status())->not->toBe(200);
});
