<?php

namespace App\Filament\Resources\Carriers\Pages;

use App\Filament\Resources\Carriers\CarrierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCarrier extends EditRecord
{
    protected static string $resource = CarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Extract and save credentials based on auth_type
        $credentials = $this->extractCredentials($data);

        // Remove virtual credential fields from data
        unset(
            $data['client_id'],
            $data['client_secret'],
            $data['auth_id'],
            $data['auth_token'],
            $data['username'],
            $data['password']
        );

        // Merge new credentials with existing ones (in case auth_type changed)
        if (! empty($credentials)) {
            $existingCredentials = $this->record->getCredentials();

            // If auth_type changed, start fresh
            if (($data['auth_type'] ?? null) !== $this->record->auth_type) {
                $this->record->setCredentials($credentials);
            } else {
                // Merge with existing (only update non-empty values)
                foreach ($credentials as $key => $value) {
                    if (! empty($value)) {
                        $existingCredentials[$key] = $value;
                    }
                }
                $this->record->setCredentials($existingCredentials);
            }
        }

        return $data;
    }

    /**
     * Extract credentials based on auth_type.
     *
     * @return array<string, mixed>
     */
    protected function extractCredentials(array $data): array
    {
        $authType = $data['auth_type'] ?? 'oauth2';
        $credentials = [];

        match ($authType) {
            'oauth2' => $credentials = array_filter([
                'client_id' => $data['client_id'] ?? null,
                'client_secret' => $data['client_secret'] ?? null,
            ]),
            'api_key' => $credentials = array_filter([
                'auth_id' => $data['auth_id'] ?? null,
                'auth_token' => $data['auth_token'] ?? null,
            ]),
            'basic' => $credentials = array_filter([
                'username' => $data['username'] ?? null,
                'password' => $data['password'] ?? null,
            ]),
            default => null,
        };

        return $credentials;
    }
}
