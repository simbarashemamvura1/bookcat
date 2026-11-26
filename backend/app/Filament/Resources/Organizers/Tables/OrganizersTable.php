<?php

namespace App\Filament\Resources\Organizers\Tables;

use App\Models\BusinessAccount;
use App\Models\Organizer;
use App\Services\OrganizerAdmin;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrganizersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('business_name')->label('Business')->searchable()->sortable(),
                TextColumn::make('account.email')->label('Login email')->searchable(),
                TextColumn::make('application_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        Organizer::STATUS_APPROVED => 'success',
                        Organizer::STATUS_PENDING => 'warning',
                        Organizer::STATUS_CHANGES_REQUESTED => 'info',
                        Organizer::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('origin')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->color('gray'),
                IconColumn::make('is_flagged')
                    ->label('Flagged')
                    ->state(fn (Organizer $record): bool => $record->flagged_at !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-flag')
                    ->trueColor('danger')
                    ->falseIcon(''),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('application_status')
                    ->label('Status')
                    ->options([
                        Organizer::STATUS_PENDING => 'Pending review',
                        Organizer::STATUS_CHANGES_REQUESTED => 'Changes requested',
                        Organizer::STATUS_APPROVED => 'Approved',
                        Organizer::STATUS_REJECTED => 'Rejected',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve this organizer?')
                        ->modalDescription('Their account becomes active.')
                        ->visible(fn (Organizer $record): bool => self::can('approve_organizer')
                            && in_array($record->application_status, [Organizer::STATUS_PENDING, Organizer::STATUS_CHANGES_REQUESTED], true))
                        ->action(fn (Organizer $record) => self::perform(
                            'Organizer approved',
                            fn () => app(OrganizerAdmin::class)->approve($record, self::admin()),
                        )),

                    Action::make('requestChanges')
                        ->label('Request changes')
                        ->icon('heroicon-o-pencil-square')
                        ->color('info')
                        ->modalHeading('Ask for changes')
                        ->schema([
                            Textarea::make('note')->label('What needs to change?')->required()->maxLength(2000),
                        ])
                        ->visible(fn (Organizer $record): bool => self::can('approve_organizer')
                            && $record->application_status === Organizer::STATUS_PENDING)
                        ->action(fn (array $data, Organizer $record) => self::perform(
                            'Changes requested',
                            fn () => app(OrganizerAdmin::class)->requestChanges($record, self::admin(), $data['note']),
                        )),

                    Action::make('reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->modalHeading('Reject this application')
                        ->schema([
                            Textarea::make('note')->label('Reason')->required()->maxLength(2000),
                        ])
                        ->visible(fn (Organizer $record): bool => self::can('approve_organizer')
                            && in_array($record->application_status, [Organizer::STATUS_PENDING, Organizer::STATUS_CHANGES_REQUESTED], true))
                        ->action(fn (array $data, Organizer $record) => self::perform(
                            'Application rejected',
                            fn () => app(OrganizerAdmin::class)->reject($record, self::admin(), $data['note']),
                        )),

                    Action::make('flag')
                        ->icon('heroicon-o-flag')
                        ->color('danger')
                        ->modalHeading('Flag this organizer')
                        ->schema([
                            Textarea::make('reason')->required()->maxLength(2000),
                        ])
                        ->visible(fn (Organizer $record): bool => self::can('suspend_organizer')
                            && $record->application_status === Organizer::STATUS_APPROVED
                            && $record->flagged_at === null)
                        ->action(fn (array $data, Organizer $record) => self::perform(
                            'Organizer flagged',
                            fn () => app(OrganizerAdmin::class)->flag($record, self::admin(), $data['reason']),
                        )),

                    Action::make('unflag')
                        ->icon('heroicon-o-flag')
                        ->requiresConfirmation()
                        ->visible(fn (Organizer $record): bool => self::can('suspend_organizer') && $record->flagged_at !== null)
                        ->action(fn (Organizer $record) => self::perform(
                            'Flag removed',
                            fn () => app(OrganizerAdmin::class)->unflag($record, self::admin()),
                        )),
                ]),
            ]);
    }

    private static function admin(): BusinessAccount
    {
        /** @var BusinessAccount $user */
        $user = Filament::auth()->user();

        return $user;
    }

    private static function can(string $permission): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof BusinessAccount && $user->hasPermission($permission);
    }

    /** Runs a service call and reports the result. Permission failures still surface as a 403. */
    private static function perform(string $successTitle, callable $callback): void
    {
        try {
            $callback();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}