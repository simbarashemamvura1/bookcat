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
}