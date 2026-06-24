<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Services\Integrations\IntegrationSyncEngine;
use App\Services\Integrations\PaceApiClient;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class EditIntegrationConnection extends EditRecord
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->action(function (): void {
                    if ($this->record->driver === 'pace') {
                        $result = (new PaceApiClient($this->record))->testConnection();
                    } else {
                        try {
                            $response = Http::timeout($this->record->timeout_seconds)->get($this->record->base_url);
                            $this->record->markConnected();
                            $result = ['success' => true, 'message' => 'Connection successful (HTTP '.$response->status().')'];
                        } catch (Exception $e) {
                            $this->record->markError($e->getMessage());
                            $result = ['success' => false, 'message' => $e->getMessage()];
                        }
                    }

                    if ($result['success']) {
                        $body = $result['message'] ?? 'Connected';
                        if (! empty($result['version'])) {
                            $body .= "\nPace Version: ".$result['version'];
                        }
                        Notification::make()->title('Connection Successful')->body($body)->success()->duration(10000)->send();
                    } else {
                        Notification::make()->title('Connection Failed')->body($result['message'] ?? 'Unknown error')->danger()->persistent()->send();
                    }
                }),

            Action::make('discover_objects')
                ->label('Discover Objects')
                ->icon('heroicon-o-magnifying-glass')
                ->color('warning')
                ->visible(fn (): bool => $this->record->driver === 'pace')
                ->requiresConfirmation()
                ->modalHeading('Discover API Objects')
                ->modalDescription('Queries the API to validate each configured object and its field mappings against live data.')
                ->action(function (): void {
                    $objects = $this->record->objects()->get();

                    if ($objects->isEmpty()) {
                        Notification::make()
                            ->title('No Objects Configured')
                            ->body('Add integration objects first, then run Discover to validate them against the API.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $engine = new IntegrationSyncEngine(new PaceApiClient($this->record));

                    $lines = [];
                    $allSuccess = true;
                    foreach ($objects as $object) {
                        $r = $engine->discoverObject($object);
                        if ($r['success']) {
                            $lines[] = "{$r['object_name']}: {$r['fields_found']} fields with data, {$r['fields_null']} empty ({$r['total_records']} total records)";
                        } else {
                            $lines[] = "{$r['object_name']}: FAILED — {$r['error']}";
                            $allSuccess = false;
                        }
                    }

                    $notification = Notification::make()->body(implode("\n", $lines));
                    $allSuccess
                        ? $notification->title('Discovery Complete')->success()->duration(15000)->send()
                        : $notification->title('Discovery Completed with Errors')->warning()->persistent()->send();
                }),

            Action::make('force_sync')
                ->label('Force Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => $this->record->driver === 'pace')
                ->requiresConfirmation()
                ->modalHeading('Force Sync Now')
                ->modalDescription('Immediately runs a full pull sync for all enabled objects on this connection.')
                ->action(function (): void {
                    try {
                        $objects = $this->record->objects()
                            ->where('sync_enabled', true)
                            ->where('sync_direction', '!=', 'push')
                            ->get();

                        if ($objects->isEmpty()) {
                            Notification::make()->title('No Objects to Sync')->body('No objects are enabled for pull sync on this connection.')->warning()->send();

                            return;
                        }

                        $engine = new IntegrationSyncEngine(new PaceApiClient($this->record));

                        $lines = [];
                        $hasErrors = false;
                        foreach ($objects as $object) {
                            $result = $engine->sync($object);
                            $errorCount = is_array($result->errors) ? count($result->errors) : (int) $result->errors;
                            $line = ($object->display_name ?? $object->object_name).": {$result->created} created, {$result->updated} updated, {$result->skipped} skipped";
                            if ($errorCount > 0) {
                                $line .= " ({$errorCount} errors)";
                                $hasErrors = true;
                            }
                            $lines[] = $line;
                        }

                        $this->record->markSynced();

                        $notification = Notification::make()->body(implode("\n", $lines));
                        $hasErrors
                            ? $notification->title('Sync Completed with Errors')->warning()->persistent()->send()
                            : $notification->title('Sync Completed')->success()->duration(15000)->send();
                    } catch (Exception $e) {
                        Notification::make()->title('Sync Failed')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Action::make('regenerate_webhook_token')
                ->label('Regenerate Webhook URL')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Regenerate Webhook Token')
                ->modalDescription('Generates a new webhook URL and invalidates the old one. Any external system using the old URL will stop working.')
                ->action(function (): void {
                    $this->record->generateWebhookToken();
                    // Reflect the new URL in the read-only field on the page (not just the popup).
                    $this->data['webhook_url_display'] = $this->record->getWebhookUrl();

                    Notification::make()
                        ->title('Webhook Token Regenerated')
                        ->body("New webhook URL:\n".$this->record->getWebhookUrl())
                        ->success()
                        ->persistent()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $encrypted = $this->record->getAttributes()['auth_credentials'] ?? null;

        $data['credentials'] = [];
        if (! empty($encrypted)) {
            try {
                $data['credentials'] = json_decode(Crypt::decryptString($encrypted), true) ?? [];
            } catch (Exception $e) {
                $data['credentials'] = [];
            }
        }

        $data['webhook_url_display'] = $this->record->webhook_token
            ? $this->record->getWebhookUrl()
            : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['credentials']) && is_array($data['credentials'])) {
            $credentials = array_filter($data['credentials'], fn ($v): bool => $v !== null && $v !== '');
            $data['auth_credentials'] = Crypt::encryptString(json_encode($credentials));
        }
        unset($data['credentials']);

        $data['updated_by'] = auth()->id();

        return $data;
    }
}
