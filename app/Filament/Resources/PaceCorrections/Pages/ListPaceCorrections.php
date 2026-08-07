<?php

namespace App\Filament\Resources\PaceCorrections\Pages;

use App\Filament\Resources\PaceCorrections\PaceCorrectionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListPaceCorrections extends ListRecords
{
    protected static string $resource = PaceCorrectionResource::class;

    /**
     * This view is a wide, data-dense table — let it use the full content width so a collapsed
     * sidebar / large monitor isn't wasted, instead of the panel's default capped container.
     */
    protected Width|string|null $maxContentWidth = Width::Full;
}
