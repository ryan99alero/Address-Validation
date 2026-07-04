<?php

namespace App\Filament\Resources\SqlConnections\Schemas;

use App\Models\SqlConnection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SqlConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('A label for this connection, e.g. "ePace Shipping DB".'),
                        Select::make('purpose')
                            ->label('Used for')
                            ->options(SqlConnection::purposes())
                            ->native(false)
                            ->helperText('What this connection is used for. Only one active connection per purpose is used.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('When off, this connection is ignored.')
                            ->columnSpanFull(),
                        Select::make('driver')
                            ->label('Driver')
                            ->options(['sqlsrv' => 'SQL Server (sqlsrv)'])
                            ->default('sqlsrv')
                            ->required(),
                        TextInput::make('host')->label('Host / IP')->maxLength(255),
                        TextInput::make('port')->label('Port')->default('1433')->maxLength(10),
                        TextInput::make('database')->label('Database')->maxLength(255),
                        TextInput::make('username')->label('Username')->maxLength(255),
                        TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Stored encrypted. Leave blank to keep the current password.')
                            ->maxLength(255),
                    ]),

                Section::make('Source Table & Field Mapping')
                    ->description('The source table and the column name for each field we read. Change a value only if your table uses a different column name.')
                    ->schema([
                        TextInput::make('table_name')
                            ->label('Table / View')
                            ->placeholder('xCarrierShipping')
                            ->live(onBlur: true)
                            ->maxLength(255),
                        Fieldset::make('Column mapping (logical field → source column)')
                            ->columns(3)
                            ->schema(self::fieldMapInputs()),
                    ]),

                Section::make('Effective Query')
                    ->description('Read-only preview of the SELECT that will run. Edit the field mapping above to change it, or enable a custom query below.')
                    ->schema([
                        Textarea::make('query_preview')
                            ->label('')
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(3)
                            ->extraInputAttributes(['style' => 'font-family:monospace'])
                            ->afterStateHydrated(fn (Textarea $c, $state, $record) => $c->state(self::previewFromState($record))),
                    ]),

                Section::make('Advanced')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Textarea::make('custom_query')
                            ->label('Custom query (optional)')
                            ->rows(3)
                            ->extraInputAttributes(['style' => 'font-family:monospace'])
                            ->helperText('Leave blank to use the generated query. Advanced: overrides the query entirely — use the tracking placeholder :trackingNumbers.'),
                        Toggle::make('encrypt')->label('Encrypt connection'),
                        Toggle::make('trust_server_certificate')->label('Trust server certificate')->default(true),
                    ]),
            ]);
    }

    /**
     * One TextInput per logical field, bound to field_map.<key>, defaulting to the source
     * column name.
     *
     * @return array<int, TextInput>
     */
    protected static function fieldMapInputs(): array
    {
        $inputs = [];
        foreach (SqlConnection::shippingFieldMapDefaults() as $logical => $default) {
            $inputs[] = TextInput::make("field_map.{$logical}")
                ->label(Str::of($logical)->replace('_', ' ')->title().($logical === 'tracking' ? ' (WHERE)' : ''))
                ->placeholder($default)
                ->maxLength(255);
        }

        return $inputs;
    }

    protected static function previewFromState(?SqlConnection $record): string
    {
        return $record?->exists ? $record->previewQuery() : (new SqlConnection)->previewQuery();
    }
}
