<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Crypt;

class CreateIntegrationConnection extends CreateRecord
{
    protected static string $resource = IntegrationConnectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (isset($data['credentials']) && is_array($data['credentials'])) {
            $credentials = array_filter($data['credentials'], fn ($v): bool => $v !== null && $v !== '');
            $data['auth_credentials'] = Crypt::encryptString(json_encode($credentials));
        }
        unset($data['credentials']);

        // Generate a webhook token up-front so the trigger URL is visible immediately.
        if (empty($data['webhook_token'])) {
            $data['webhook_token'] = bin2hex(random_bytes(32));
        }

        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
