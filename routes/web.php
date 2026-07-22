<?php

use App\Models\ImportBatch;
use App\Models\RecentItem;
use Filament\Notifications\Notification;
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

// Recents fail-soft redirector: /recent/{item} → the stored URL, or (for a since-deleted record)
// drop the stale row + notify + land on the resource index. Returns a redirect, so the capture
// middleware ignores it. Both the sidebar links and the spotlight commands point here.
Route::get('/recent/{recentItem}', function (RecentItem $recentItem) {
    abort_unless($recentItem->user_id === auth()->id(), 403);

    if ($recentItem->type === RecentItem::TYPE_RECORD
        && $recentItem->filament_class
        && class_exists($recentItem->filament_class)
        && ! $recentItem->filament_class::getModel()::query()->whereKey($recentItem->record_key)->exists()) {
        $recentItem->delete();
        Notification::make()->title('That item no longer exists')->warning()->send();

        return redirect($recentItem->filament_class::getUrl('index'));
    }

    return redirect($recentItem->url);
})->middleware('auth')->name('recent.go');

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

// Generic grid CSV export download — files written by GridCsv into storage/app/exports. A normal GET
// (not a Livewire streamDownload) so the browser reliably downloads; deleted after send.
Route::get('/exports/grid/{file}', function (string $file) {
    $name = basename($file); // strip any path traversal
    abort_unless(str_ends_with($name, '.csv'), 404);
    $path = Storage::disk('local')->path('exports/'.$name);
    abort_unless(is_file($path), 404, 'Export not found or already downloaded');

    return response()->download($path)->deleteFileAfterSend();
})->middleware(['auth'])->name('grid-export.download');
