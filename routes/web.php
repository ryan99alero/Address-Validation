<?php

use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Register the logout route that Filament expects
Route::post('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('filament.admin.auth.logout');

// Legacy URL redirects — these resources/pages moved into Filament clusters during the nav
// rebuild, so their standalone paths (existing bookmarks) now live under a cluster prefix.
// Redirect the index path and any deeper sub-path (create/edit/etc.) to the new location.
$clusterMoves = [
    'carriers' => 'integrations/carriers',
    'integration-connections' => 'integrations/integration-connections',
    'folder-integrations' => 'integrations/folder-integrations',
    'mail-integrations' => 'integrations/mail-integrations',
    'sql-connections' => 'integrations/sql-connections',
    'charge-categories' => 'charge-classification/charge-categories',
    'charge-drivers' => 'charge-classification/charge-drivers',
    'export-templates' => 'templates/export-templates',
    'import-field-templates' => 'templates/import-field-templates',
    'export-template-builder' => 'templates/export-template-builder',
];
foreach ($clusterMoves as $old => $new) {
    Route::redirect("/{$old}", "/{$new}");
    Route::get("/{$old}/{rest}", fn (string $rest): RedirectResponse => redirect("/{$new}/{$rest}"))
        ->where('rest', '.*');
}

// Export download route
Route::get('/batch-processing/download/{batch}', function (ImportBatch $batch) {
    if (! $batch->export_file_path || $batch->export_status !== 'completed') {
        abort(404, 'Export not ready');
    }

    $filePath = Storage::disk('local')->path($batch->export_file_path);

    if (! file_exists($filePath)) {
        abort(404, 'Export file not found');
    }

    return response()->download($filePath);
})->middleware(['auth'])->name('filament.admin.pages.batch-processing.download');
