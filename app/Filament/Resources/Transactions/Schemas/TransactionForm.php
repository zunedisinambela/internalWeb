<?php

namespace App\Filament\Resources\Transactions\Schemas;

use App\Enums\TransactionType;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Models\Source;
use App\Models\Transaction;
use App\Rules\WholeRupiah;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(2)
                    ->components([
                        // Two mutually exclusive choices with their own colour
                        // and icon, so the direction of the money is readable
                        // before the amount is. A Select would hide it behind a
                        // click on the screen where getting it wrong is worst.
                        ToggleButtons::make('type')
                            ->label('Jenis')
                            ->options(TransactionType::class)
                            ->default(TransactionType::Expense)
                            ->inline()
                            ->required()
                            ->columnSpanFull(),

                        // Lewat mana uangnya berpindah. Satu daftar untuk
                        // kedua arah: pemasukan masuk *ke* rekening ini dan
                        // pengeluaran keluar *dari* rekening ini, dan keduanya
                        // menyebut baris yang sama — itulah yang membuat saldo
                        // per rekening bisa dihitung sama sekali.
                        //
                        // Wajib di sini meski kolomnya nullable. Yang boleh
                        // kosong hanya baris yang dicatat sebelum kolom ini
                        // ada; sejak sekarang jawabannya selalu diketahui saat
                        // mencatat, dan menawarkan pilihan kosong berarti
                        // mengundang lubang yang tidak perlu ada.
                        Select::make('source_id')
                            ->label('Sumber dana')
                            ->relationship(
                                'source',
                                'name',
                                // Yang aktif, ditambah yang sedang dipakai
                                // baris ini walau sudah dinonaktifkan. Tanpa
                                // bagian kedua, membuka transaksi lama
                                // menampilkan field kosong dan menekan Simpan
                                // menghapus sumbernya tanpa pesan apa pun.
                                modifyQueryUsing: fn (Builder $query, ?Model $record): Builder => $query
                                    ->selectable($record?->source_id)
                                    ->orderBy('name'),
                            )
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull()
                            // Mencatat transaksi tidak boleh terhenti karena
                            // dompetnya belum terdaftar. Formnya dipinjam utuh
                            // dari layar Sumber dana, jadi aturan nama unik dan
                            // teks bantuannya tidak perlu ditulis dua kali.
                            ->createOptionForm(fn (Schema $schema): Schema => SourceForm::configure($schema))
                            ->createOptionUsing(fn (array $data): int => Source::create($data)->getKey())
                            ->createOptionModalHeading('Tambah sumber dana')
                            ->helperText('Dompet atau rekening yang uangnya bergerak. Belum ada? Tambahkan langsung dari sini.'),

                        TextInput::make('amount')
                            ->label('Jumlah')
                            ->prefix('Rp')
                            // Neither ->numeric() nor ->integer(): both make
                            // getType() return "number", and a number input
                            // cannot render a thousands separator — the browser
                            // rejects "1.500.000" and shows an empty field. The
                            // rules they used to register live in WholeRupiah
                            // instead, so dropping them costs no validation.
                            ->inputMode('numeric')
                            ->maxLength(19)
                            ->required()
                            ->rule(new WholeRupiah)
                            // Regroups on blur rather than per keystroke.
                            // Filament v5 does not bundle Alpine's mask plugin
                            // — no directive("mask"), no magic("money") in its
                            // Alpine build — so ->mask(RawJs::make('$money(…)'))
                            // renders an attribute nothing implements and
                            // silently does nothing. A blur costs one Livewire
                            // round trip and is testable from PHPUnit, which
                            // a client-side mask would not be.
                            ->live(onBlur: true)
                            ->afterStateUpdated(static function (Set $set, mixed $state): void {
                                // Regroups anything that can only be read one
                                // way, however untidy — "10.0000" is what an
                                // already-formatted 10.000 becomes when one more
                                // digit is typed, and it plainly means 100.000.
                                // "1500.75" is left exactly as typed instead:
                                // stripping its dot would silently turn
                                // Rp 1.500,75 into Rp 150.075, so the rule above
                                // has to be the one that refuses it.
                                if (! WholeRupiah::isUnambiguous($state)) {
                                    return;
                                }

                                $set('amount', WholeRupiah::format($state));
                            })
                            // The column stores a bare integer; the field shows
                            // it grouped. These two are inverses, and both go
                            // through WholeRupiah so they cannot drift apart.
                            ->formatStateUsing(static fn (mixed $state): ?string => WholeRupiah::format($state))
                            ->dehydrateStateUsing(static fn (mixed $state): ?int => WholeRupiah::toInteger($state))
                            ->helperText('Rupiah penuh, tanpa sen. Titik ribuan ditata otomatis.'),

                        // Defaults to the moment the form is opened, which is
                        // what "waktu saat dibuat" means in practice. It stays
                        // editable: a receipt found later has to be datable to
                        // when the money actually moved, not to when it was
                        // typed in. now() is already WIB — APP_TIMEZONE is
                        // Asia/Jakarta and timestamps are stored in local time,
                        // so nothing is converted here.
                        DateTimePicker::make('occurred_at')
                            ->label('Tanggal dan waktu')
                            ->default(now())
                            ->seconds(false)
                            ->displayFormat('d M Y H:i')
                            ->native(false)
                            ->required()
                            ->maxDate(now()->addDay())
                            ->helperText('Otomatis terisi waktu sekarang. Ubah bila transaksi terjadi di lain waktu.'),

                        TextInput::make('description')
                            ->label('Keterangan')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Bukti')
                    ->description('Foto struk, nota atau bukti transfer. Bisa lebih dari satu.')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('receipts')
                            ->hiddenLabel()
                            ->collection(Transaction::RECEIPTS)
                            ->disk('local')
                            // The switch that makes Filament ask for a signed,
                            // expiring URL instead of a plain one. Without it
                            // the component builds a public /storage link and
                            // the private disk answers 403 — every preview
                            // silently turns into a broken image.
                            ->visibility('private')
                            ->conversion(Transaction::THUMBNAIL)
                            ->multiple()
                            ->reorderable()
                            // Without this a second upload replaces the first
                            // set rather than adding to it.
                            ->appendFiles()
                            ->image()
                            ->imageEditor()
                            ->openable()
                            ->downloadable()
                            ->panelLayout('grid')
                            ->maxFiles(10)
                            ->maxSize(5 * 1024)
                            // Repeated from the collection deliberately: this
                            // rejects the file in the browser with a message,
                            // the collection rejects it server-side with an
                            // exception. Only one of the two is a good
                            // experience, and only one of the two is enforcement.
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('JPG, PNG atau WEBP. Maksimal 10 berkas, masing-masing 5 MB.'),
                    ]),
            ])
            ->columns(1);
    }
}
