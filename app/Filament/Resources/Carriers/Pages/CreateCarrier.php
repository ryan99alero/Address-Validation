<?php

namespace App\Filament\Resources\Carriers\Pages;

use App\Filament\Resources\Carriers\CarrierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrier extends CreateRecord
{
    protected static string $resource = CarrierResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Extract credential fields based on auth_type
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

        return $data;
    }

    protected function afterCreate(): void
    {
        // Get the original form data to extract credentials
        $data = $this->form->getState();
        $credentials = $this->extractCredentials($data);

        if (! empty($credentials)) {
            $this->record->setCredentials($credentials);
            $this->record->save();
        }
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
