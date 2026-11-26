<?php

namespace App\Filament\Resources\Organizers\Pages;

use App\Filament\Resources\Organizers\OrganizerResource;
use Filament\Resources\Pages\EditRecord;

class EditOrganizer extends EditRecord
{
    protected static string $resource = OrganizerResource::class;

    // No delete: organizers are referenced by events, orders and payouts.
    protected function getHeaderActions(): array
    {
        return [];
    }

    // The read-only Review box shows values that are not columns on the organizer itself.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['status_label'] = str($this->record->application_status)->replace('_', ' ')->title()->toString();
        $data['login_email'] = $this->record->account?->email;

        return $data;
    }
}