<?php

namespace App\Filament\Resources\MailIntegrations\Pages;

use App\Filament\Resources\MailIntegrations\MailIntegrationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Crypt;

class EditMailIntegration extends EditRecord
{
    protected static string $resource = MailIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Re-encrypt credentials, keeping existing passwords when fields are left blank.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existing = $this->record->getCredentials();

        $data['credentials'] = Crypt::encryptString(json_encode([
            'imap_password' => filled($data['imap_password'] ?? null)
                ? $data['imap_password']
                : ($existing['imap_password'] ?? null),
            'zip_password' => filled($data['zip_password'] ?? null)
                ? $data['zip_password']
                : ($existing['zip_password'] ?? null),
        ]));

        unset($data['imap_password'], $data['zip_password']);

        return $data;
    }
}
