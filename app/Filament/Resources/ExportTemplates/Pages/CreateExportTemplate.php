<?php

namespace App\Filament\Resources\ExportTemplates\Pages;

use App\Filament\Pages\ExportTemplateBuilder;
use App\Filament\Resources\ExportTemplates\ExportTemplateResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateExportTemplate extends CreateRecord
{
    protected static string $resource = ExportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addTransitTimeFields')
                ->label('Add Transit Time Fields')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->action(function () {
                    $this->addTransitTimeFields();
                }),
        ];
    }

    /**
     * Add transit time fields to the field_layout, avoiding duplicates.
     */
    protected function addTransitTimeFields(): void
    {
        $transitFields = ExportTemplateBuilder::getTransitTimeFields();

        // Get current field_layout from form data
        $currentLayout = $this->data['field_layout'] ?? [];

        // Get existing field names to avoid duplicates
        $existingFields = collect($currentLayout)
            ->pluck('field')
            ->filter()
            ->toArray();

        // Filter to only fields not already added
        $fieldsToAdd = array_filter($transitFields, function ($field) use ($existingFields) {
            return ! in_array($field['field'], $existingFields, true);
        });

        if (empty($fieldsToAdd)) {
            Notification::make()
                ->title('No Fields Added')
                ->body('All transit time fields are already in the template.')
                ->info()
                ->send();

            return;
        }

        // Calculate starting position (after existing fields)
        $nextPosition = count($currentLayout);

        // Add the new fields to the layout with positions
        foreach ($fieldsToAdd as $field) {
            $currentLayout[] = [
                'field' => $field['field'],
                'header' => $field['header'],
                'position' => $nextPosition++,
            ];
        }

        // Update the form data
        $this->data['field_layout'] = $currentLayout;

        // Refresh the form to show the new fields
        $this->form->fill($this->data);

        $addedCount = count($fieldsToAdd);
        $skippedCount = count($transitFields) - $addedCount;

        $message = "Added {$addedCount} transit time fields to the template.";
        if ($skippedCount > 0) {
            $message .= " ({$skippedCount} already existed)";
        }

        Notification::make()
            ->title('Transit Time Fields Added')
            ->body($message)
            ->success()
            ->send();
    }
}
