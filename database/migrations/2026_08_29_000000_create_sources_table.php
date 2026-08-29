<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dompet dan rekening yang uangnya benar-benar berpindah — Kas Tunai, BCA,
 * Dana — lalu kolom di transactions yang menunjuk ke salah satunya.
 *
 * Satu daftar untuk kedua arah, bukan satu daftar per jenis. "Sumber" di sini
 * menjawab *lewat mana* uangnya bergerak, bukan *dari apa* ia datang: sebuah
 * pemasukan masuk ke BCA dan sebuah pengeluaran keluar dari BCA, dan keduanya
 * menyebut BCA yang sama. Kalau daftarnya dipisah per jenis, saldo per rekening
 * tidak akan pernah bisa dihitung — dan itulah satu-satunya angka yang membuat
 * kolom ini berguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();

            // Unik supaya "BCA" tidak bisa dicatat dua kali. SQLite
            // membandingkan TEXT secara case sensitive, jadi "bca" tetap lolos
            // — App\Models\Source menormalkan huruf depan pada saat menyimpan
            // agar batas itu tidak jadi lubang.
            $table->string('name')->unique();

            // Nomor rekening, nama pemilik, atau catatan bebas. Opsional:
            // "Kas Tunai" tidak punya nomor apa pun.
            $table->string('note')->nullable();

            // Sumber yang tidak dipakai lagi disembunyikan dari form, bukan
            // dihapus — lihat restrictOnDelete di bawah.
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Nullable, meski form mewajibkannya. Baris yang sudah ada dicatat
            // sebelum kolom ini lahir dan tidak ada yang tahu lewat mana uang
            // itu bergerak; mengisinya dengan sumber pertama yang kebetulan ada
            // berarti mengarang catatan keuangan. Kosong dan terbaca "Tidak
            // diketahui" itu jujur, dan bisa dibetulkan satu per satu.
            //
            // restrictOnDelete, bukan nullOnDelete seperti user_id di sebelahnya.
            // Keduanya menolak cascade karena alasan yang sama — menghapus
            // sesuatu tidak boleh menghapus catatan uangnya — tapi konsekuensi
            // null-nya berbeda. Nama pencatat yang hilang masih menyisakan
            // transaksi yang utuh; sumber yang hilang menghapus satu-satunya
            // keterangan lewat mana uang itu berpindah, dan itu tidak bisa
            // disusun ulang dari kolom lain. Jadi penghapusan ditolak di tingkat
            // basis data, dan jalan keluarnya adalah is_active = false.
            $table->foreignId('source_id')
                ->nullable()
                ->after('description')
                ->constrained()
                ->restrictOnDelete();

            // Setiap rekap per sumber menyaring kolom ini lalu menjumlah per
            // jenis; laporan buku kas mengurutkannya per tanggal.
            $table->index(['source_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['source_id', 'type']);
            $table->dropConstrainedForeignId('source_id');
        });

        Schema::dropIfExists('sources');
    }
};
