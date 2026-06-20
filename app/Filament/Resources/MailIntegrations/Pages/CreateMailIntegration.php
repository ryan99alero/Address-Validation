<?php

namespace App\Filament\Resources\MailIntegrations\Pages;

use App\Filament\Resources\MailIntegrations\MailIntegrationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Crypt;

class CreateMailIntegration extends CreateRecord
{
    protected static string $resource = MailIntegrationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['credentials'] = Crypt::encryptString(json_encode([
            'imap_password' => $data['imap_password'] ?? null,
            'zip_password' => $data['zip_password'] ?? null,
        ]));

        unset($data['imap_password'], $data['zip_password']);

        return $data;
    }
}
