<?php

namespace App\Filament\Resources\Organizers\Schemas;

use App\Models\BusinessAccount;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Business')
                    ->schema([
                        TextInput::make('business_name')->required()->maxLength(255),
                        TextInput::make('contact_phone')->tel()->maxLength(50),
                        Textarea::make('bio')->rows(4)->maxLength(2000)->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Login account')
                    ->description('The organizer signs in with this account. Share the temporary password with them directly.')
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('account_name')->label('Contact name')->required()->maxLength(255)->autocomplete(false),
                        TextInput::make('account_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->autocomplete(false)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (BusinessAccount::whereRaw('lower(email) = ?', [strtolower(trim((string) $value))])->exists()) {
                                    $fail('That email is already used by another account.');
                                }
                            }),
                        TextInput::make('account_password')
                            ->label('Temporary password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(12)
                            ->maxLength(100)
                            ->autocomplete('new-password'),
                        Select::make('origin')
                            ->label('How this organizer joins')
                            ->options([
                                'sales_assisted' => 'Sales-assisted: needs approval',
                                'pre_approved' => 'Pre-approved: live immediately (needs extra permission)',
                            ])
                            ->default('sales_assisted')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Review')
                    ->visibleOn('edit')
                    ->schema([
                        TextInput::make('status_label')->label('Status')->disabled()->dehydrated(false),
                        TextInput::make('login_email')->label('Login email')->disabled()->dehydrated(false),
                        Textarea::make('review_notes')->label('Review notes')->disabled()->dehydrated(false)->columnSpanFull(),
                        Textarea::make('flag_reason')->label('Flag reason')->disabled()->dehydrated(false)->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}