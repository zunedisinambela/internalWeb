<?php

namespace App\Filament\Resources\Activities\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Aktivitas')
                    ->columns(3)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('Waktu')
                            ->dateTime('d M Y H:i:s')
                            ->helperText(fn (Activity $record): string => $record->created_at?->diffForHumans() ?? ''),

                        TextEntry::make('event')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'created' => 'success',
                                'updated' => 'warning',
                                'deleted' => 'danger',
                                'restored' => 'info',
                                default => 'gray',
                            })
                            ->placeholder('—'),

                        TextEntry::make('log_name')
                            ->label('Log')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),

                        TextEntry::make('description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Pelaku dan objek')
                    ->columns(2)
                    ->components([
                        TextEntry::make('causer')
                            ->label('Pelaku')
                            ->state(fn (Activity $record): string => $record->causer?->name
                                ?? ($record->causer_type ? class_basename($record->causer_type)." #{$record->causer_id}" : 'Sistem'))
                            ->helperText(fn (Activity $record): ?string => $record->causer_type
                                ? $record->causer_type." #{$record->causer_id}"
                                : 'Not attributed to a logged-in user'),

                        TextEntry::make('subject')
                            ->label('Objek')
                            ->state(fn (Activity $record): string => $record->subject_type
                                ? class_basename($record->subject_type)." #{$record->subject_id}"
                                : '—')
                            ->helperText(fn (Activity $record): ?string => $record->subject_type),
                    ]),

                // attribute_changes is stored as ['old' => [...], 'attributes' => [...]].
                // The section hides itself when a log entry carries no diff, which is
                // the case for plain activity()->log() calls.
                Section::make('Perubahan')
                    ->columns(2)
                    ->visible(fn (Activity $record): bool => filled($record->attribute_changes))
                    ->components([
                        KeyValueEntry::make('attribute_changes.old')
                            ->label('Sebelum')
                            ->keyLabel('Attribute')
                            ->valueLabel('Old value')
                            ->state(fn (Activity $record): array => self::stringifyValues($record->attribute_changes?->get('old') ?? []))
                            ->emptyMessage('No previous values recorded.'),

                        KeyValueEntry::make('attribute_changes.attributes')
                            ->label('Sesudah')
                            ->keyLabel('Attribute')
                            ->valueLabel('New value')
                            ->state(fn (Activity $record): array => self::stringifyValues($record->attribute_changes?->get('attributes') ?? []))
                            ->emptyMessage('No new values recorded.'),
                    ]),

                Section::make('Properti')
                    ->collapsed()
                    ->visible(fn (Activity $record): bool => filled($record->properties))
                    ->components([
                        TextEntry::make('properties')
                            ->hiddenLabel()
                            ->fontFamily(FontFamily::Mono)
                            ->state(fn (Activity $record): string => json_encode(
                                $record->properties?->toArray() ?? [],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ) ?: '{}')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * KeyValueEntry renders scalars only. Nested arrays and objects are encoded
     * so a cast attribute (json column, array cast, enum) does not blow up the page.
     *
     * @param  iterable<string, mixed>  $values
     * @return array<string, string>
     */
    protected static function stringifyValues(iterable $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            $result[$key] = match (true) {
                is_null($value) => '—',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            };
        }

        return $result;
    }
}
