<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Models\FloorPlan;
use App\Models\Organizer;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Organizer and venue')
                    ->description('These cannot be changed after the event is created.')
                    ->schema([
                        Select::make('organizer_id')
                            ->label('Organizer')
                            ->required()
                            ->searchable()
                            ->live()
                            ->options(fn (string $operation): array => self::organizerOptions($operation))
                            ->afterStateUpdated(fn (Set $set) => $set('floor_plan_id', null))
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        Select::make('floor_plan_id')
                            ->label('Floor plan')
                            ->required()
                            ->searchable()
                            ->live()
                            ->options(fn (Get $get): array => self::floorPlanOptions($get('organizer_id')))
                            ->helperText('Ready templates, plus any custom plan built for this organizer.')
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                    ])
                    ->columns(2),

                Section::make('Event')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('category')->maxLength(100),
                        Select::make('currency')
                            ->options(['CAD' => 'CAD', 'USD' => 'USD'])
                            ->default('CAD')
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! config("bookcat.fees.{$value}")) {
                                    $fail("No fee schedule is set up for {$value} yet.");
                                }
                            }),
                        DateTimePicker::make('starts_at')
                            ->label('Starts')
                            ->required()
                            ->seconds(false)
                            ->timezone(fn (Get $get): string => self::venueTimezone($get('floor_plan_id')))
                            ->helperText(fn (Get $get): string => 'Venue local time ('.self::venueTimezone($get('floor_plan_id')).').'),
                        DateTimePicker::make('ends_at')
                            ->label('Ends')
                            ->seconds(false)
                            ->after('starts_at')
                            ->timezone(fn (Get $get): string => self::venueTimezone($get('floor_plan_id'))),
                        Toggle::make('is_free')
                            ->label('Fully free event')
                            ->helperText('No fees at all, and every ticket price must be 0.')
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->columnSpanFull(),
                        Textarea::make('description')->rows(4)->maxLength(5000)->columnSpanFull(),
                        Textarea::make('restrictions')->label('Restrictions (age, ID, and so on)')->rows(2)->maxLength(2000)->columnSpanFull(),
                        Textarea::make('refund_policy')
                            ->label('Refund policy')
                            ->helperText('Shown to buyers. Refunds are issued by an admin.')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    /** @return array<int, string> */
    private static function organizerOptions(string $operation): array
    {
        $query = Organizer::query()->orderBy('business_name');

        // When creating, only organizers who can actually receive an event. When viewing, everyone.
        if ($operation !== 'edit') {
            $query->where('application_status', Organizer::STATUS_APPROVED)->whereNull('flagged_at');
        }

        return $query->pluck('business_name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function floorPlanOptions(mixed $organizerId): array
    {
        $organizer = $organizerId ? Organizer::find($organizerId) : null;

        if (! $organizer) {
            return [];
        }

        return FloorPlan::selectableBy($organizer)
            ->with('venue')
            ->get()
            ->mapWithKeys(fn (FloorPlan $plan) => [$plan->id => ($plan->venue?->name ?? 'Venue').' — '.$plan->name])
            ->all();
    }

    private static function venueTimezone(mixed $floorPlanId): string
    {
        if (! $floorPlanId) {
            return 'America/Toronto';
        }

        return FloorPlan::with('venue')->find($floorPlanId)?->venue?->timezone ?? 'America/Toronto';
    }
}