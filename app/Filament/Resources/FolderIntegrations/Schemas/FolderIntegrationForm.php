<?php

namespace App\Filament\Resources\FolderIntegrations\Schemas;

use App\Models\Carrier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FolderIntegrationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Folder')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Integration Name')
                            ->placeholder('e.g. UPS Invoices (Server)')
                            ->required()
                            ->maxLength(255),
                        Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(Carrier::pluck('name', 'id'))
                            ->helperText('All files in this folder are treated as this carrier')
                            ->required(),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ]),

                Section::make('Connection')
                    ->columns(2)
                    ->schema([
                        Select::make('connection_type')
                            ->label('Connection Type')
                            ->options([
                                'local' => 'Local / Mounted path (recommended)',
                                'smb' => 'SMB share (server + credentials)',
                            ])
                            ->default('local')
                            ->live()
                            ->required()
                            ->helperText('If the Windows share is already mounted on this machine/server, use Local and point at the mount path.'),
                        TextInput::make('base_path')
                            ->label(fn ($get): string => $get('connection_type') === 'smb' ? 'Path within Share' : 'Folder Path')
                            ->placeholder('/Volumes/Accounting/Accounts Payable/UPS Invoices')
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Type the path EXACTLY as it appears in Finder — plain spaces, NO backslash escapes. Year sub-folders are scanned recursively.')
                            ->rule(static function ($get) {
                                return static function (string $attribute, $value, \Closure $fail) use ($get): void {
                                    if ($get('connection_type') === 'local' && ! is_dir(rtrim((string) $value, '/'))) {
                                        $fail('That folder was not found or is not accessible. Type it exactly as in Finder — plain spaces, no backslashes.');
                                    }
                                };
                            }),

                        // SMB-only fields
                        TextInput::make('smb_host')
                            ->label('Server Name / IP')
                            ->placeholder('enterprise.randgraphics.com')
                            ->visible(fn ($get): bool => $get('connection_type') === 'smb')
                            ->required(fn ($get): bool => $get('connection_type') === 'smb'),
                        TextInput::make('smb_share')
                            ->label('Share / UNC Root')
                            ->placeholder('Accounting')
                            ->visible(fn ($get): bool => $get('connection_type') === 'smb')
                            ->required(fn ($get): bool => $get('connection_type') === 'smb'),
                        TextInput::make('smb_username')
                            ->label('Username')
                            ->placeholder('DOMAIN\\username  (e.g. RAND\\jdoe)')
                            ->helperText('For an Active Directory account include the domain: DOMAIN\\username.')
                            ->visible(fn ($get): bool => $get('connection_type') === 'smb'),
                        TextInput::make('smb_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->visible(fn ($get): bool => $get('connection_type') === 'smb')
                            ->helperText('Stored encrypted. Use DOMAIN\\username for an AD account. After saving, hit "Test" to verify the connection.'),
                    ]),

                Section::make('Scanning')
                    ->columns(2)
                    ->schema([
                        TextInput::make('file_pattern')
                            ->label('File Types')
                            ->default('*.csv,*.pdf')
                            ->required()
                            ->helperText('Comma-separated. CSV is preferred when both exist for an invoice.'),
                        Toggle::make('prefer_csv')->label('Prefer CSV over PDF')->default(true),
                        Toggle::make('recursive')->label('Scan sub-folders (years)')->default(true),
                        Select::make('poll_minutes')
                            ->label('Check Frequency')
                            ->options([
                                0 => 'Manual only',
                                60 => 'Hourly',
                                360 => 'Every 6 hours',
                                720 => 'Every 12 hours',
                                1440 => 'Daily',
                            ])
                            ->default(0),
                    ]),
            ]);
    }
}
