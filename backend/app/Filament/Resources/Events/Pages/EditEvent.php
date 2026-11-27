<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    // No delete: orders, tickets and payouts reference events.
    protected function getHeaderActions(): array
    {
        return [];
    }

    // After saving, go back to the Events list.
    protected function getRedirectUrl(): string
    {
        return EventResource::getUrl('index');
    }
}