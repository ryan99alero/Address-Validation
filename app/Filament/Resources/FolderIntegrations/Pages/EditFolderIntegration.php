<?php

namespace App\Filament\Resources\FolderIntegrations\Pages;

use App\Filament\Resources\FolderIntegrations\FolderIntegrationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Crypt;

class EditFolderIntegration extends EditRecord
{
    protected static string $resource = FolderIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $creds = $this->record->getCredentials();
        $data['smb_username'] = $creds['smb_username'] ?? null;
        $data['smb_password'] = $creds['smb_password'] ?? null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['credentials'] = Crypt::encryptString(json_encode([
            'smb_username' => $data['smb_username'] ?? null,
            'smb_password' => $data['smb_password'] ?? null,
        ]));
        unset($data['smb_username'], $data['smb_password']);

        return $data;
    }
}
