<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Services\EventAdmin;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    // The event, its fee terms and its seat snapshot are created together, with the checks in the service.
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(EventAdmin::class)->create(Filament::auth()->user(), $data);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}