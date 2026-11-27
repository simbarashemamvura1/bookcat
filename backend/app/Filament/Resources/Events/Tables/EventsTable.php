<?php

namespace App\Filament\Resources\Events\Tables;

use App\Models\BusinessAccount;
use App\Models\Event;
use App\Services\EventAdmin;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organizer', 'floorPlan.venue']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('organizer.business_name')->label('Organizer')->searchable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('M j, Y g:i A')
                    ->timezone(fn (Event $record): string => $record->venue?->timezone ?? 'UTC')
                    ->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('stage')
                    ->label('Stage')
                    ->state(fn (Event $record): string => self::stage($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'On sale' => 'success',
                        'Public', 'Activated', 'Approved' => 'info',
                        'Sales frozen', 'Rejected' => 'danger',
                        'Sold out' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->state(fn (Event $record): bool => $record->made_public_at !== null)
                    ->boolean(),
                IconColumn::make('is_bookable')
                    ->label('Bookable')
                    ->state(fn (Event $record): bool => $record->made_bookable_at !== null)
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('review_status')
                    ->label('Review')
                    ->options([
                        Event::REVIEW_DRAFT => 'Draft',
                        Event::REVIEW_SUBMITTED => 'Submitted',
                        Event::REVIEW_APPROVED => 'Approved',
                        Event::REVIEW_REJECTED => 'Rejected',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::setPricesAction(),

                    self::gate('approve', 'Approve', 'approve_event',
                        fn (Event $e) => in_array($e->review_status, [Event::REVIEW_DRAFT, Event::REVIEW_SUBMITTED], true),
                        'Event approved', 'approve', 'success',
                        'Every seat must have a price. After approval the seat layout and prices are locked.'),

                    self::rejectAction(),

                    self::gate('markFeePaid', 'Mark creation fee paid', 'activate_event',
                        fn (Event $e) => ! $e->is_free && $e->creation_fee_paid_at === null,
                        'Creation fee marked as paid', 'markCreationFeePaid', 'gray',
                        'Only do this once the fee has actually been received.'),

                    self::gate('activate', 'Activate', 'activate_event',
                        fn (Event $e) => $e->isApproved() && ! $e->isActivated(),
                        'Event activated', 'activate', 'success'),

                    self::gate('deactivate', 'Deactivate', 'activate_event',
                        fn (Event $e) => $e->isActivated(),
                        'Event deactivated', 'deactivate', 'danger',
                        'This hides the event from the public, even if it is marked Public.'),

                    self::gate('makePublic', 'Make public', 'create_event_directly',
                        fn (Event $e) => $e->made_public_at === null,
                        'Event is now public', 'makePublic', 'success'),

                    self::gate('makePrivate', 'Make private', 'create_event_directly',
                        fn (Event $e) => $e->made_public_at !== null,
                        'Event is now private', 'makePrivate', 'gray',
                        'Visitors will no longer see this event.'),

                    self::gate('makeBookable', 'Make bookable', 'create_event_directly',
                        fn (Event $e) => $e->made_bookable_at === null,
                        'Event is now bookable', 'makeBookable', 'success'),

                    self::gate('makeNotBookable', 'Stop bookings', 'create_event_directly',
                        fn (Event $e) => $e->made_bookable_at !== null,
                        'Bookings turned off', 'makeNotBookable', 'gray',
                        'Nobody will be able to buy tickets until it is made bookable again.'),

                    self::gate('freeze', 'Freeze sales', 'freeze_event_booking',
                        fn (Event $e) => $e->made_bookable_at !== null && $e->sales_status === Event::SALES_OPEN,
                        'Sales frozen', 'freeze', 'danger',
                        'Buyers cannot start new purchases until sales are reactivated.'),

                    self::gate('reactivate', 'Reactivate sales', 'reactivate_event',
                        fn (Event $e) => $e->sales_status === Event::SALES_FROZEN,
                        'Sales reactivated', 'reactivate', 'success'),
                ]),
            ]);
    }

    /** A one-word summary of how far the event has got through the gates. */
    private static function stage(Event $event): string
    {
        if ($event->sales_status === Event::SALES_FROZEN) {
            return 'Sales frozen';
        }

        if ($event->sales_status === Event::SALES_SOLD_OUT) {
            return 'Sold out';
        }

        if ($event->isPurchasable()) {
            return 'On sale';
        }

        if ($event->isVisibleToPublic()) {
            return 'Public';
        }

        if ($event->isActivated()) {
            return 'Activated';
        }

        if ($event->isApproved()) {
            return 'Approved';
        }

        return str($event->review_status)->title()->toString();
    }

    /** One release-step button: permission, visibility rule, optional confirmation, and the service call. */
    private static function gate(
        string $name,
        string $label,
        string $permission,
        Closure $visibleWhen,
        string $success,
        string $method,
        string $color = 'gray',
        ?string $confirm = null,
    ): Action {
        $action = Action::make($name)
            ->label($label)
            ->color($color)
            ->visible(fn (Event $record): bool => self::can($permission) && $visibleWhen($record))
            ->action(fn (Event $record) => self::perform(
                $success,
                fn () => app(EventAdmin::class)->{$method}($record, self::admin()),
            ));

        if ($confirm !== null) {
            $action->requiresConfirmation()->modalDescription($confirm);
        }

        return $action;
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->modalHeading('Reject this event')
            ->schema([
                Textarea::make('note')->label('Reason')->required()->maxLength(2000),
            ])
            ->visible(fn (Event $record): bool => self::can('approve_event')
                && in_array($record->review_status, [Event::REVIEW_DRAFT, Event::REVIEW_SUBMITTED], true))
            ->action(fn (array $data, Event $record) => self::perform(
                'Event rejected',
                fn () => app(EventAdmin::class)->reject($record, self::admin(), $data['note']),
            ));
    }

    private static function setPricesAction(): Action
    {
        return Action::make('setPrices')
            ->label('Set prices')
            ->modalHeading('Set ticket prices by section')
            ->modalDescription(fn (Event $record): string => 'Prices are in '.$record->currency.'. Every seat in a section gets the same price. Enter 0 for free.')
            ->fillForm(fn (Event $record): array => ['prices' => self::sectionRows($record)])
            ->schema([
                Repeater::make('prices')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        TextInput::make('section')->readOnly(),
                        TextInput::make('seats')->label('Seats')->readOnly(),
                        TextInput::make('price')->label('Price')->numeric()->minValue(0)->step(0.01)->required(),
                    ])
                    ->columns(3),
            ])
            ->visible(fn (Event $record): bool => self::can('create_event_directly')
                && $record->review_status === Event::REVIEW_DRAFT)
            ->action(function (array $data, Event $record) {
                $prices = collect($data['prices'])
                    ->map(fn (array $row): array => [
                        'section' => (string) $row['section'],
                        'cents' => (int) round(((float) $row['price']) * 100),
                    ])
                    ->all();

                self::perform('Prices saved', fn () => app(EventAdmin::class)->setSectionPrices($record, self::admin(), $prices));
            });
    }

    /** @return array<int, array{section: string, seats: int, price: float|int|null}> */
    private static function sectionRows(Event $event): array
    {
        return $event->seats()
            ->select('section', DB::raw('count(*) as seat_count'), DB::raw('max(price_cents) as top_price'))
            ->groupBy('section')
            ->orderBy('section')
            ->get()
            ->map(fn ($row): array => [
                'section' => $row->section,
                'seats' => (int) $row->seat_count,
                'price' => $row->top_price === null ? null : $row->top_price / 100,
            ])
            ->all();
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