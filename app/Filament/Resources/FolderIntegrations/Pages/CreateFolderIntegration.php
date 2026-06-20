<?php

namespace App\Filament\Resources\FolderIntegrations\Pages;

use App\Filament\Resources\FolderIntegrations\FolderIntegrationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Crypt;

class CreateFolderIntegration extends CreateRecord
{
    protected static string $resource = FolderIntegrationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['credentials'] = Crypt::encryptString(json_encode([
            'smb_username' => $data['smb_username'] ?? null,
            'smb_password' => $data['smb_password'] ?? null,
        ]));
        unset($data['smb_username'], $data['smb_password']);

        return $data;
    }
}
