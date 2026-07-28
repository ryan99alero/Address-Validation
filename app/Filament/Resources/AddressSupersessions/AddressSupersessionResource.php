<?php

namespace App\Filament\Resources\AddressSupersessions;

use App\Filament\Resources\AddressSupersessions\Pages\ListAddressSupersessions;
use App\Filament\Resources\AddressSupersessions\Tables\AddressSupersessionsTable;
use App\Models\AddressSupersession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Re-Corrections review queue: every time the carrier corrected an address we already hold as good,
 * shown as a from → to event. Clean local ZIP-only drifts auto-thread; state changes, different
 * buildings, and long-distance jumps land here for a human to Apply or Dismiss. Rejected garbage
 * (own-dock, cross-country) is listed too, so refusals are visible rather than silent.
 */
class AddressSupersessionResource extends Resource
{
    protected static ?string $model = AddressSupersession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Address Intelligence';

    protected static ?string $navigationLabel = 'Re-Corrections';

    protected static ?string $modelLabel = 'Re-Correction';

    protected static ?string $slug = 'address-recorrections';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $n = AddressSupersession::query()->where('status', AddressSupersession::STATUS_PENDING_REVIEW)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return AddressSupersessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAddressSupersessions::route('/'),
        ];
    }
}
