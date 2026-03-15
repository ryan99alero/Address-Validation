<?php

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Register the logout route that Filament expects
Route::post('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/');
})->name('filament.admin.auth.logout');

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
