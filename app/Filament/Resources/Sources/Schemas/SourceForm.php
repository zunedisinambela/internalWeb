<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Models\Source;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sumber dana')
                    ->description('Dompet atau rekening tempat uang benar-benar berpindah.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            // Kolomnya unique, tapi SQLite membandingkan TEXT
                            // secara case sensitive — jadi "bca" lolos di
                            // sebelah "BCA" dan saldo rekening itu terbelah dua
                            // tanpa ada yang salah di layar. Aturan ini
                            // membandingkan versi huruf kecilnya, yang adalah
                            // arti "sama" yang sebenarnya dimaksud di sini.
                            ->rule(static fn (?Model $record): callable => static function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                $taken = Source::query()
                                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                                    ->when($record, fn (Builder $q, Model $record): Builder => $q->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($taken) {
                                    $fail('Sumber dana dengan nama itu sudah ada.');
                                }
                            })
                            ->helperText('Misalnya Kas Tunai, BCA, atau Dana.'),

                        TextInput::make('note')
                            ->label('Keterangan')
                            ->maxLength(255)
                            ->helperText('Nomor rekening atau catatan bebas. Boleh dikosongkan.'),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Sumber tidak aktif tidak muncul saat mencatat transaksi baru, tapi catatan lamanya tetap utuh.'),
                    ]),
            ])
            ->columns(1);
    }
}
