<?php

namespace App\Filament\Resources\Organizers\Pages;

use App\Filament\Resources\Organizers\OrganizerResource;
use App\Services\OrganizerAdmin;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrganizer extends CreateRecord
{
    protected static string $resource = OrganizerResource::class;

    // The login account and the organizer are created together, with the permission checks in the service.
    protected function handleRecordCreation(array $data): Model
    {
        return app(OrganizerAdmin::class)->create(Filament::auth()->user(), $data);
    }
}