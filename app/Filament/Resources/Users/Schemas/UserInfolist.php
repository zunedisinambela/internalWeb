<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name'),

                        TextEntry::make('email')
                            ->copyable(),

                        TextEntry::make('email_verified_at')
                            ->label('Verified')
                            ->dateTime('d M Y H:i')
                            ->placeholder('Not verified'),

                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('d M Y H:i'),
                    ]),

                Section::make('Access')
                    ->components([
                        TextEntry::make('roles.name')
                            ->label('Roles')
                            ->badge()
                            ->placeholder('No access')
                            ->helperText(fn (User $record): string => $record->roles()->exists()
                                ? 'Can sign in to this panel and the log viewer.'
                                : 'Cannot sign in to this panel or the log viewer.'),
                    ]),
            ]);
    }
}
