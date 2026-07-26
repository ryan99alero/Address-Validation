<?php

namespace App\Filament\Pages;

use App\Enums\ChargeDriver;
use App\Filament\Concerns\HasApplyReclassificationAction;
use App\Filament\Resources\CarrierCharges\CarrierChargeResource;
use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every distinct charge the carriers have billed us (raw code + description), shown next to the
 * canonical category and driver we normalize it to — the "100% of charges" audit view. Backed by a
 * grouped query so Filament paginates at the DB level (there are thousands of distinct charge types
 * — loading them all into a collection exhausts memory). A null category flags a mapping gap.
 */
class CarrierChargeCatalog extends Page implements HasTable
{
    use HasApplyReclassificationAction;
    use InteractsWithTable;

    protected string $view = 'filament.pages.carrier-charge-catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Carrier Costs';

    // Reached from Adjustments (Carrier Charges) via its "Charge Catalog" button — not its own top
    // menu item, to keep the nav lean.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Carrier Charge Catalog';

    protected function getHeaderActions(): array
    {
        return [
            $this->applyReclassificationAction(),
            Action::make('adjustments')
                ->label('Back to Adjustments')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(CarrierChargeResource::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->catalogQuery())
            ->columns([
                TextColumn::make('carrier')->badge()->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'FedEx' => 'purple', 'UPS' => 'warning', default => 'gray',
                    }),
                TextColumn::make('source_type')->label('Format')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state))->placeholder('—'),
                TextColumn::make('code')->label('Raw Code')->fontFamily('mono')->placeholder('—')
                    ->searchable(query: fn (Builder $q, string $s): Builder => $q->orWhere('carrier_charges.code', 'like', "%{$s}%")),
                TextColumn::make('description')->label('Carrier Description')->wrap()->limit(48)
                    ->searchable(query: fn (Builder $q, string $s): Builder => $q->orWhere('carrier_charges.description', 'like', "%{$s}%")),
                TextColumn::make('category')->label('→ Category')->badge()
                    ->getStateUsing(fn (CarrierCharge $record): string => $record->category ?? 'UNMAPPED')
                    ->color(fn (CarrierCharge $record): string => $record->category !== null ? 'gray' : 'danger'),
                TextColumn::make('driver')->label('→ Driver')->badge()->color('info')
                    ->getStateUsing(fn (CarrierCharge $record): string => $record->driver !== null ? (ChargeDriver::tryFrom($record->driver)?->abbreviation() ?? $record->driver) : '—'),
                TextColumn::make('line_count')->label('Lines')->numeric()->sortable()->alignEnd(),
                TextColumn::make('total')->label('Total Billed')->money('USD')->sortable()->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('carrier_id')
                    ->label('Carrier')
                    ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->attribute('carrier_charges.carrier_id'),
                TernaryFilter::make('mapping')
                    ->label('Mapping')
                    ->placeholder('All charge types')
                    ->trueLabel('Mapped only')
                    ->falseLabel('Unmapped only')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('carrier_charges.charge_category_id'),
                        false: fn (Builder $q): Builder => $q->whereNull('carrier_charges.charge_category_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->recordActions([
                $this->mapChargeAction(),
            ])
            ->defaultSort('total', 'desc')
            ->paginated([50, 100])
            ->emptyStateHeading('No charges imported yet');
    }

    /**
     * Map a charge to a category from the catalog: create (or update) the crosswalk row for this
     * carrier + label in the format it appeared, then re-categorize its existing charges. Hidden for
     * parser reconciliation residuals, which have no charge identity.
     */
    protected function mapChargeAction(): Action
    {
        return Action::make('map')
            ->label('Map')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading('Map charge to a category')
            ->modalSubmitActionLabel('Save mapping')
            ->hidden(fn (CarrierCharge $record): bool => $this->isResidual((string) $record->description))
            ->fillForm(fn (CarrierCharge $record): array => [
                'display_name' => $record->description,
                'charge_category_id' => $record->charge_category_id,
            ])
            ->schema([
                Placeholder::make('context')
                    ->label('Carrier charge')
                    ->content(fn (CarrierCharge $record): string => trim(sprintf(
                        '%s · %s · %s',
                        $record->carrier ?? 'Carrier',
                        strtoupper((string) ($record->source_type ?? '—')),
                        $record->description ?? '—',
                    ))),
                TextInput::make('display_name')->label('Name')->required()->maxLength(255),
                Select::make('charge_category_id')
                    ->label('Our Category')
                    ->options(fn (): array => ChargeCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('— Needs review —')
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data, CarrierCharge $record): void {
                CarrierChargeType::mapCharge(
                    $record->carrier_id,
                    trim((string) $record->description),
                    $record->source_type === 'pdf',
                    trim((string) ($data['display_name'] ?? '')),
                    $data['charge_category_id'] ?? null,
                );

                Notification::make()
                    ->title('Charge mapped')
                    ->body('Re-categorizing existing charges of this type in the background.')
                    ->success()
                    ->send();
            });
    }

    private function isResidual(string $description): bool
    {
        return str_starts_with($description, 'UPS charge (unclassified')
            || str_starts_with($description, 'UPS credit/adjustment (unclassified');
    }

    /**
     * Aggregate ~850k charge lines into one row per distinct charge type inside a DERIVED table,
     * then select from it. The derived table is aliased as the model's own table name so Filament's
     * stable-pagination tie-break (ORDER BY carrier_charges.id) resolves to the derived MIN(id)
     * column — legal under MySQL ONLY_FULL_GROUP_BY, where ordering by the raw grouped column is not.
     */
    protected function catalogQuery(): Builder
    {
        $aggregate = CarrierCharge::query()
            ->toBase()
            ->leftJoin('carriers as ca', 'ca.id', '=', 'carrier_charges.carrier_id')
            ->leftJoin('charge_categories as c', 'c.id', '=', 'carrier_charges.charge_category_id')
            ->groupBy(
                'carrier_charges.carrier_id',
                'ca.name',
                'carrier_charges.raw_charge_code',
                'carrier_charges.raw_charge_description',
                'carrier_charges.source_type',
                'carrier_charges.charge_category_id',
                'c.abbreviation',
                'carrier_charges.driver',
            )
            ->selectRaw('
                MIN(carrier_charges.id) AS id,
                carrier_charges.carrier_id AS carrier_id,
                ca.name AS carrier,
                carrier_charges.raw_charge_code AS code,
                carrier_charges.raw_charge_description AS description,
                carrier_charges.source_type AS source_type,
                carrier_charges.charge_category_id AS charge_category_id,
                c.abbreviation AS category,
                carrier_charges.driver AS driver,
                COUNT(*) AS line_count,
                ROUND(SUM(carrier_charges.amount), 2) AS total
            ');

        return CarrierCharge::query()->fromSub($aggregate, 'carrier_charges')->select('carrier_charges.*');
    }
}
