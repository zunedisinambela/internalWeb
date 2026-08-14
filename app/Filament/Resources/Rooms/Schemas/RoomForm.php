<?php

namespace App\Filament\Resources\Rooms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kamar')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nama kamar')
                            ->required()
                            ->maxLength(255)
                            // Unique in the schema, so it has to be checked here
                            // too — otherwise the form hands back a constraint
                            // violation instead of a message under the field.
                            // ignoreRecord keeps an edit from colliding with
                            // itself.
                            ->unique(ignoreRecord: true)
                            ->helperText('Seperti yang tertulis di pintu. Contoh: A3, Kamar 7.'),

                        TextInput::make('occupant')
                            ->label('Penghuni')
                            ->maxLength(255)
                            ->helperText('Kosongkan bila kamar sedang tidak dihuni.'),

                        Toggle::make('is_active')
                            ->label('Kamar aktif')
                            ->default(true)
                            ->helperText('Matikan untuk kamar yang tidak lagi disewakan. Riwayat meterannya tetap tersimpan.'),

                        TextInput::make('note')
                            ->label('Catatan')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }
}
