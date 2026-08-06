<?php

namespace App\Filament\Resources\MailIntegrations\Schemas;

use App\Models\Carrier;
use App\Models\MailIntegration;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MailIntegrationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Mailbox')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Integration Name')
                            ->placeholder('e.g. UPS Invoices Mailbox')
                            ->required()
                            ->maxLength(255),
                        Select::make('carrier_detection')
                            ->label('Determine Carrier By')
                            ->options([
                                'sender_domain' => 'Sender email domain (e.g. @ups.com)',
                                'file_content' => 'File content / header (read the CSV or PDF)',
                                'fixed' => 'Fixed carrier (this mailbox is one carrier only)',
                            ])
                            ->default('file_content')
                            ->live()
                            ->required()
                            ->helperText('One mailbox can receive both UPS and FedEx — this decides how each invoice is routed and which Carrier/Year/Month folder it is archived to'),
                        Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(Carrier::pluck('name', 'id'))
                            ->visible(fn ($get): bool => $get('carrier_detection') === 'fixed')
                            ->required(fn ($get): bool => $get('carrier_detection') === 'fixed')
                            ->helperText('Used when every invoice in this mailbox is from a single carrier'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive integrations are skipped by the scheduled poll'),
                        Select::make('poll_minutes')
                            ->label('Check Frequency')
                            ->options(MailIntegration::pollFrequencyOptions())
                            ->default(0)
                            ->required()
                            ->helperText('How often the scheduler checks this mailbox and processes any new invoices. "Manual only" = use the Fetch button.'),
                    ]),

                Section::make('IMAP Connection')
                    ->columns(2)
                    ->schema([
                        TextInput::make('imap_host')
                            ->label('Host')
                            ->placeholder('mail.yourdomain.com')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('imap_port')
                            ->label('Port')
                            ->numeric()
                            ->default(993)
                            ->required(),
                        Select::make('imap_encryption')
                            ->label('Encryption')
                            ->options([
                                'ssl' => 'SSL',
                                'tls' => 'TLS',
                                'starttls' => 'STARTTLS',
                                'none' => 'None',
                            ])
                            ->default('ssl')
                            ->required(),
                        Toggle::make('imap_validate_cert')
                            ->label('Validate TLS Certificate')
                            ->default(true)
                            ->helperText('Turn off only for self-signed certs you trust'),
                        TextInput::make('imap_username')
                            ->label('Username')
                            ->placeholder('invoices@yourdomain.com')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('imap_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password'
                                : 'Stored encrypted'),
                        TextInput::make('imap_folder')
                            ->label('Folder')
                            ->default('INBOX')
                            ->required(),
                        Select::make('imap_sequence')
                            ->label('IMAP Command Mode')
                            ->options([
                                'uid' => 'Standard (UID) — most servers',
                                'msgn' => 'Message number — Zimbra & some older servers',
                            ])
                            ->default('uid')
                            ->required()
                            ->helperText('Leave on Standard. Auto-switches to Message number if the server rejects UID commands.'),
                        TextInput::make('processed_folder')
                            ->label('Move processed emails to')
                            ->placeholder('Processed')
                            ->helperText('Optional. Leave blank to just mark as read'),
                    ]),

                Section::make('Attachments & Archive')
                    ->columns(2)
                    ->schema([
                        TextInput::make('attachment_pattern')
                            ->label('Attachment Pattern')
                            ->default('*.zip')
                            ->required()
                            ->helperText('Case-insensitive glob for attachments to process. ZIPs are decompressed; PDFs/CSVs are ingested directly. UPS = Invoices_Accounts_*.zip, FedEx = *.pdf'),
                        TextInput::make('from_filter')
                            ->label('From Filter (recommended)')
                            ->placeholder('ups.com')
                            ->helperText('Only process emails from this address/domain. Strongest spam guard — e.g. ups.com or fedex.com'),
                        TextInput::make('subject_filter')
                            ->label('Subject Contains (optional)')
                            ->placeholder('Invoice')
                            ->helperText('Extra filter: only emails whose subject contains this text'),
                        TextInput::make('zip_password')
                            ->label('ZIP Password (static)')
                            ->password()
                            ->revealable()
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password'
                                : 'The static password the carrier uses for emailed ZIPs. Stored encrypted'),
                        TextInput::make('archive_disk')
                            ->label('Archive Disk')
                            ->default('local')
                            ->required()
                            ->helperText('Laravel filesystem disk to write archived files to'),
                        TextInput::make('archive_base_path')
                            ->label('Archive Base Path')
                            ->default('invoices/processed')
                            ->required()
                            ->helperText('Files are filed as {base}/{Carrier}/{Year}/{Month}/'),
                    ]),
            ]);
    }
}
