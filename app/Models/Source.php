<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu dompet atau rekening yang uangnya benar-benar berpindah: Kas Tunai,
 * BCA, Dana.
 *
 * Dipakai oleh kedua arah buku kas. Sebuah pemasukan masuk *ke* sumber ini dan
 * sebuah pengeluaran keluar *dari* sumber ini, jadi keduanya menunjuk baris
 * yang sama — itulah yang membuat balance() di bawah bisa berarti apa-apa.
 *
 * ## Yang tidak dijawab kolom ini
 *
 * Pindah uang antar rekening tercatat sebagai dua baris: pengeluaran dari BCA
 * dan pemasukan ke Kas Tunai. Saldo tiap rekening tetap benar, tapi "total
 * pemasukan" di ringkasan ikut naik oleh uang yang sebenarnya tidak masuk dari
 * mana pun. Selama pemindahan seperti itu jarang, itu lebih murah daripada
 * jenis transaksi ketiga; kalau nanti sering, jawabannya adalah `transfer`
 * sebagai TransactionType dan bukan kolom baru di sini.
 *
 * balance() juga menghitung dari nol, bukan dari saldo awal rekening. Yang
 * dilaporkan adalah "berapa yang bergerak lewat sini menurut buku ini", bukan
 * "berapa isi rekeningnya". Menambahkan saldo awal berarti panel ini mengklaim
 * angka yang tidak pernah dilihatnya.
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Rapikan nama sebelum disimpan.
     *
     * Spasi ganda dan spasi di ujung tidak terlihat di layar tapi membuat
     * "BCA " dan "BCA" jadi dua rekening berbeda dengan dua saldo yang
     * masing-masing setengah benar. Kolomnya unique, jadi tanpa ini batas itu
     * bisa dilewati dengan menekan spasi.
     */
    public function setNameAttribute(?string $value): void
    {
        $this->attributes['name'] = $value === null
            ? null
            : trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Sumber yang boleh dipilih di sebuah form.
     *
     * Yang aktif, ditambah satu yang sedang dipakai baris yang diedit meski
     * sudah dinonaktifkan. Bagian kedua itu yang gampang hilang: sebuah Select
     * membangun pilihannya dari kueri ini, jadi kalau sumber lama tidak ikut,
     * field-nya tampil kosong pada transaksi yang jelas-jelas punya sumber —
     * dan menekan Simpan menulis null ke kolomnya tanpa pesan apa pun.
     *
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function scopeSelectable(Builder $query, ?int $keep = null): Builder
    {
        return $query->where(
            fn (Builder $q): Builder => $q
                ->where('is_active', true)
                ->when($keep, fn (Builder $q, int $id): Builder => $q->orWhere('id', $id)),
        );
    }

    /**
     * Berapa yang tersisa menurut buku, dalam rupiah penuh.
     *
     * Satu agregat, bukan dua, dengan alasan yang sama seperti
     * Transaction::balance(): dua kueri terpisah bisa dijalankan atas himpunan
     * baris yang sudah berubah di antaranya, lalu menghasilkan selisih yang
     * tidak pernah benar-benar ada.
     */
    public function balance(): int
    {
        return (int) $this->transactions()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE -amount END), 0) AS balance',
                [TransactionType::Income->value],
            )
            ->value('balance');
    }

    /**
     * Jejak audit. Daftar kolomnya eksplisit, sama seperti model lain: sebuah
     * allowlist tidak bisa membocorkan kolom yang ditambahkan belakangan.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'note', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('source');
    }
}
