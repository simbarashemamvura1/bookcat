<?php

namespace App\Filament\Resources\Organizers;

use App\Filament\Resources\Organizers\Pages\CreateOrganizer;
use App\Filament\Resources\Organizers\Pages\EditOrganizer;
use App\Filament\Resources\Organizers\Pages\ListOrganizers;
use App\Filament\Resources\Organizers\Schemas\OrganizerForm;
use App\Filament\Resources\Organizers\Tables\OrganizersTable;
use App\Models\Organizer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrganizerResource extends Resource
{
    protected static ?string $model = Organizer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'business_name';

    public static function form(Schema $schema): Schema
    {
        return OrganizerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrganizersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizers::route('/'),
            'create' => CreateOrganizer::route('/create'),
            'edit' => EditOrganizer::route('/{record}/edit'),
        ];
    }
}
