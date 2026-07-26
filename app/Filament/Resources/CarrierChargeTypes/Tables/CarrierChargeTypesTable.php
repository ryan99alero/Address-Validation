<?php

namespace App\Filament\Resources\CarrierChargeTypes\Tables;

use App\Models\CarrierChargeType;
use App\Models\ChargeCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarrierChargeTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carrier.name')->label('Carrier')->badge()->placeholder('Generic')
                    ->color(fn (?string $state): string => match ($state) {
                        'FedEx' => 'purple', 'UPS' => 'warning', default => 'gray',
                    })->sortable(),
                TextColumn::make('display_name')->label('Name')->searchable()->sortable()->wrap(),
                TextColumn::make('csv_label')->label('CSV Label')->searchable()->fontFamily('mono')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('pdf_label')->label('PDF Label')->searchable()->fontFamily('mono')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('csv_code')->label('CSV Code')->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                SelectColumn::make('charge_category_id')
                    ->label('Our Category')
                    ->options(fn (): array => ChargeCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->selectablePlaceholder(true)
                    ->afterStateUpdated(function (CarrierChargeType $record): void {
                        $record->recategorizeAffectedCharges();
                        Notification::make()
                            ->title('Category updated')
                            ->body('Re-categorizing existing charges of this type in the background.')
                            ->success()
                            ->send();
                    }),
                TextColumn::make('match_style')->label('Match')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('charges_count')->label('Charges')->counts('charges')->numeric()->sortable()->alignEnd(),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('charge_category_id')
                    ->label('Categorization')
                    ->placeholder('All')
                    ->trueLabel('Categorized')
                    ->falseLabel('Needs review')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('charge_category_id'),
                        false: fn (Builder $q): Builder => $q->whereNull('charge_category_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('display_name');
    }
}
