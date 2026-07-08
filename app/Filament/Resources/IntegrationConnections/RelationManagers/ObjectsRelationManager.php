<?php

namespace App\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Models\IntegrationConnection;
use App\Models\IntegrationObject;
use App\Services\Integrations\PaceApiClient;
use App\Services\ModelDiscoveryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ObjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'objects';

    protected static ?string $title = 'Objects';

    protected static string|BackedEnum|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        $discovery = new ModelDiscoveryService;
        $connection = $this->getOwnerRecord();
        $isPace = $connection instanceof IntegrationConnection && $connection->driver === IntegrationConnection::DRIVER_PACE;

        // For Pace connections, populate Object Name from the live API object list
        // (parsed from the Pace swagger). Other drivers fall back to free text.
        $objectNameField = $isPace
            ? Select::make('object_name')
                ->label('Object Name')
                ->required()
                ->searchable()
                ->live()
                ->options(fn (): array => (new PaceApiClient($connection))->getCommonObjectTypes())
                ->helperText('Available Pace API objects (pulled from the API schema)')
            : TextInput::make('object_name')
                ->label('Object Name')
                ->required()
                ->maxLength(100)
                ->live()
                ->helperText('The API object name, e.g. Customer');

        return $schema
            ->columns(1)
            ->components([
                Section::make('Object Definition')
                    ->description('Define the Pace API object and the local model it maps to')
                    ->columns(2)
                    ->schema([
                        $objectNameField,
                        TextInput::make('display_name')
                            ->required()
                            ->maxLength(100)
                            ->helperText('Friendly name shown in the UI'),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                        TextInput::make('primary_key_field')
                            ->label('API Primary Key XPath')
                            ->default('@id')
                            ->required()
                            ->helperText('XPath to the API primary key (e.g. @id)'),
                        Select::make('primary_key_type')
                            ->options(['String' => 'String', 'Integer' => 'Integer'])
                            ->default('Integer')
                            ->required(),
                        Select::make('local_model')
                            ->label('Local Model')
                            ->options(fn () => $discovery->getModelOptionsForSelect())
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('local_table', ($state && class_exists($state)) ? (new $state)->getTable() : null);
                            })
                            ->helperText('Local Eloquent model to sync into (e.g. Address)'),
                        TextInput::make('local_table')->disabled()->dehydrated(),
                        TextInput::make('default_filter')
                            ->label('Default Filter (XPath)')
                            ->columnSpanFull()
                            ->placeholder("@state = 'TX'")
                            ->helperText('Optional ePace XPath filter applied to every pull'),
                    ]),

                Section::make('Sync')
                    ->columns(4)
                    ->schema([
                        Toggle::make('sync_enabled')->label('Enabled')->inline(false),
                        Select::make('sync_direction')
                            ->options(['pull' => 'Pull', 'push' => 'Push', 'bidirectional' => 'Bidirectional'])
                            ->default('pull')
                            ->required(),
                        Select::make('api_method')
                            ->label('API Method')
                            ->options([
                                'loadValueObjects' => 'loadValueObjects (read)',
                                'findObjects' => 'findObjects (read)',
                                'createObject' => 'createObject (write)',
                                'updateObject' => 'updateObject (write)',
                            ])
                            ->default('loadValueObjects')
                            ->helperText('Reads pull data in; writes (createObject/updateObject) push data out — e.g. JobCost chargebacks.')
                            ->required(),
                        Select::make('sync_frequency')
                            ->options(['manual' => 'Manual', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly'])
                            ->default('manual'),
                    ]),

                Section::make('Field Mappings')
                    ->description('Pull: map Pace fields → local columns (mark Pull). Push/write-back: set Field Name to the Contact field, Local/Source Field to the corrected value (address1, city, residential, …), and mark Push.')
                    ->schema([
                        Repeater::make('fieldMappings')
                            ->relationship()
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeMapping($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeMapping($data))
                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::unwrapMapping($data))
                            ->schema([
                                Select::make('field_picker')
                                    ->label('Pick Field')
                                    ->dehydrated(false)
                                    ->searchable()
                                    ->visible($isPace)
                                    ->options(function (Get $get) use ($connection, $isPace): array {
                                        $objectName = $get('../../object_name');
                                        if (! $isPace || ! $objectName) {
                                            return [];
                                        }

                                        // Schema fields (standard) + live-discovered fields (incl. custom),
                                        // cached on the object via the "Read Live Fields" action.
                                        $swagger = (new PaceApiClient($connection))->getObjectFields($objectName);
                                        $object = $connection->objects()->where('object_name', $objectName)->first();

                                        // available_fields may be flat strings (Read Live Fields) or
                                        // structured {name, xpath, ...} entries (Discover Objects).
                                        $live = [];
                                        foreach ((array) ($object?->available_fields ?? []) as $f) {
                                            $name = is_array($f) ? ($f['name'] ?? null) : $f;
                                            if (is_string($name) && $name !== '') {
                                                $live[$name] = $name;
                                            }
                                        }

                                        return array_merge($swagger, $live);
                                    })
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if ($state) {
                                            $set('external_xpath', '@'.$state);
                                            $set('external_field', $state);
                                        }
                                    })
                                    ->helperText("Fills the XPath from the object's real fields"),
                                TextInput::make('external_xpath')
                                    ->label('API XPath')
                                    ->required()
                                    ->helperText('@firstName, or /country/@isoCountry — or pick a field at left'),
                                TextInput::make('external_field')
                                    ->label('Field Name')
                                    ->helperText('Logical key (defaults from XPath)'),
                                Select::make('external_type')
                                    ->label('API Type')
                                    ->options(['String' => 'String', 'Integer' => 'Integer', 'Date' => 'Date', 'Boolean' => 'Boolean', 'Float' => 'Float'])
                                    ->default('String')
                                    ->required(),
                                TextInput::make('local_field')
                                    ->label('Local / Source Field')
                                    ->helperText('For Push (write-back): the corrected field to send — address1, address2, address3, city, state, zip, postal_ext, country, residential. For Pull: the local DB column. Blank = fetch-only.'),
                                Select::make('local_type')
                                    ->label('Local Type')
                                    ->options(['string' => 'string', 'integer' => 'integer', 'float' => 'float', 'boolean' => 'boolean', 'datetime' => 'datetime', 'date' => 'date'])
                                    ->default('string'),
                                Select::make('transform')
                                    ->label('Transform')
                                    ->placeholder('None (pass-through)')
                                    ->options(self::transformOptions())
                                    ->live(),
                                KeyValue::make('transform_options')
                                    ->label('Transform Options')
                                    ->visible(fn (Get $get): bool => in_array($get('transform'), ['value_map', 'fk_lookup'], true))
                                    ->helperText('fk_lookup: keys model, match_column, return_column. value_map: each from=to pair.')
                                    ->columnSpanFull(),
                                Toggle::make('is_identifier')->label('Identifier (match key)')->inline(false),
                                Toggle::make('sync_on_pull')->label('Pull')->default(true)->inline(false)
                                    ->helperText('Include when pulling from Pace'),
                                Toggle::make('sync_on_push')->label('Push')->default(false)->inline(false)
                                    ->helperText('Write this field back to Pace (the Contact address write-back)'),
                            ])
                            ->columns(3)
                            ->itemLabel(fn (array $state): ?string => self::itemLabel($state))
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function normalizeMapping(array $data): array
    {
        if (empty($data['external_field'])) {
            $data['external_field'] = $data['local_field'] ?: ltrim(basename($data['external_xpath'] ?? ''), '@');
        }

        // value_map stores its pairs under transform_options.map
        if (($data['transform'] ?? null) === 'value_map'
            && isset($data['transform_options'])
            && is_array($data['transform_options'])
            && ! isset($data['transform_options']['map'])) {
            $data['transform_options'] = ['map' => $data['transform_options']];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function unwrapMapping(array $data): array
    {
        if (($data['transform'] ?? null) === 'value_map' && isset($data['transform_options']['map'])) {
            $data['transform_options'] = $data['transform_options']['map'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected static function itemLabel(array $state): ?string
    {
        $xpath = $state['external_xpath'] ?? '';
        $local = $state['local_field'] ?? '';

        if ($xpath && $local) {
            return "{$xpath} → {$local}";
        }

        return $xpath ?: null;
    }

    /**
     * @return array<string, string>
     */
    protected static function transformOptions(): array
    {
        return [
            'date_ms_to_carbon' => 'Date (ms epoch) → datetime',
            'date_iso_to_carbon' => 'Date (ISO) → datetime',
            'cents_to_dollars' => 'Cents → dollars',
            'string_to_int' => 'String → int',
            'string_to_float' => 'String → float',
            'string_to_bool' => 'String → bool',
            'json_decode' => 'JSON decode',
            'trim' => 'Trim',
            'uppercase' => 'Uppercase',
            'lowercase' => 'Lowercase',
            'value_map' => 'Value map',
            'fk_lookup' => 'FK lookup',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('object_name')->label('Object')->searchable()->sortable(),
                TextColumn::make('display_name')->label('Display Name')->searchable()->sortable(),
                TextColumn::make('local_model')
                    ->label('Local Model')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                IconColumn::make('sync_enabled')->label('Enabled')->boolean(),
                TextColumn::make('sync_direction')
                    ->label('Direction')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pull' => 'info',
                        'push' => 'warning',
                        'bidirectional' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('field_mappings_count')
                    ->label('Fields')
                    ->counts('fieldMappings')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_synced_at')->label('Last Synced')->since()->placeholder('Never'),
            ])
            ->headerActions([
                CreateAction::make()->slideOver()->modalWidth('5xl'),
            ])
            ->recordActions([
                Action::make('read_live_fields')
                    ->label('Read Live Fields')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->visible(fn (IntegrationObject $record): bool => $record->connection?->driver === IntegrationConnection::DRIVER_PACE)
                    ->requiresConfirmation()
                    ->modalHeading('Read Live Fields from Pace')
                    ->modalDescription('Reads one live record of this object and caches its actual field names — including custom fields the schema does not list — so they appear in the field picker.')
                    ->action(function (IntegrationObject $record): void {
                        try {
                            $fields = (new PaceApiClient($record->connection))->discoverLiveFields($record->object_name);

                            if (empty($fields)) {
                                Notification::make()
                                    ->title('No Record Found')
                                    ->body("Couldn't read a sample {$record->object_name} record (none exist, or the API returned nothing).")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->update(['available_fields' => array_keys($fields)]);

                            Notification::make()
                                ->title('Live Fields Cached')
                                ->body(count($fields)." fields read from a live {$record->object_name} record (custom fields included). They now appear in the field picker.")
                                ->success()
                                ->duration(10000)
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Read Failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                EditAction::make()->slideOver()->modalWidth('5xl'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
