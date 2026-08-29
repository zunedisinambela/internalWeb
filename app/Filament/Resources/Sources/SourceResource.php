<?php

namespace App\Filament\Resources\Sources;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Daftar dompet dan rekening, di sebelah buku kas yang memakainya.
 *
 * Data acuan, bukan fitur tersendiri: layar ini disiapkan sekali lalu jarang
 * dibuka, sementara /transactions dipakai tiap hari. Itu sebabnya
 * navigationSort-nya di bawah buku kas, pola yang sama dengan grup Oriflame.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Sumber dana';

    // Bahasa Indonesia tidak mengenal infleksi jamak; kalau hanya satu yang
    // diisi, Filament mencetak "Sumber danas".
    protected static ?string $modelLabel = 'sumber dana';

    protected static ?string $pluralModelLabel = 'sumber dana';

    // Tepat di bawah buku kas (10), jauh di atas layar monitoring (90+).
    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    /**
     * Saldo dan jumlah transaksi ikut dimuat sebagai subkueri.
     *
     * Kolom saldo membacanya, dan canDelete() di bawah membaca jumlahnya —
     * tanpa ini, keduanya satu kueri per baris.
     */
    public static function getEloquentQuery(): Builder
    {
        return SourcesTable::withBalance(parent::getEloquentQuery());
    }

    /**
     * Sumber yang sudah dipakai tidak boleh dihapus.
     *
     * Basis datanya sendiri sudah menolak — foreign key-nya restrictOnDelete —
     * tapi penolakan itu datang sebagai QueryException di tengah aksi Filament,
     * yaitu layar error, bukan pesan. Pemeriksaannya diletakkan di resource dan
     * bukan pada tombolnya karena Filament menanyai resource untuk aksi baris
     * *dan* untuk setiap baris di dalam aksi massal; memasangnya di
     * ->visible() saja meninggalkan jalur massal terbuka, dan di sanalah
     * QueryException itu akan muncul.
     *
     * Jalan keluarnya adalah menonaktifkan, bukan menghapus. Itu bukan
     * kompromi: sebuah rekening yang pernah dipakai adalah bagian dari catatan,
     * dan menghilangkannya membuat transaksi lama tidak bisa dibaca lagi.
     */
    public static function canDelete(Model $record): bool
    {
        return (int) ($record->transactions_count ?? $record->transactions()->count()) === 0
            && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }
}
