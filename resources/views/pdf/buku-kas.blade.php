{{--
    Buku kas sebagai PDF.

    Gaya, kepala dan kartu ringkasan datang dari pdf/partials — empat laporan
    memakainya, jadi satu perubahan tampilan tidak perlu diulang empat kali.

    Semua teks dari pengguna ditulis dengan {{ }}, tidak pernah {!! !!}. Itu bukan
    kebiasaan kosong di sini: `chroot` dompdf adalah base_path() dan `file://`
    ada di allowed_protocols, sehingga markup yang lolos ke dalam dokumen bisa
    membaca berkas mana pun di proyek — .env termasuk, dan APP_KEY di dalamnya
    membuka secret dua faktor setiap pengguna. Lihat bagian PDF di CLAUDE.md.

    Itu berlaku untuk `src` gambar juga. Path yang dicetak di kolom Bukti
    disusun App\Support\PdfImage dari nama berkas yang diunggah pengguna, jadi
    ia melewati {{ }} seperti teks lainnya — sebuah nama berkas bertanda kutip
    akan keluar dari atribut kalau tidak.

    Footer dan nomor halaman tidak ada di sini. dompdf tidak punya counter
    `pages`, jadi counter(pages) CSS selalu mencetak 0, sementara $PAGE_COUNT
    menuntut `enable_php`. Keduanya dihindari: App\Jobs\ExportReport menulis
    footer lewat Canvas::page_text() setelah render.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Buku Kas</title>
    <style>
        @include('pdf.partials.gaya')
    </style>
</head>
<body>

@include('pdf.partials.kop', [
    'judul' => 'Buku Kas',
    'jumlah' => $totals['rows'],
    'ringkas' => [$totals['rows'].' transaksi'],
])

@include('pdf.partials.ringkasan', ['kartu' => [
    ['label' => 'Total pemasukan', 'nilai' => \App\Support\Rupiah::format($totals['income']), 'kelas' => 'masuk'],
    ['label' => 'Total pengeluaran', 'nilai' => \App\Support\Rupiah::format($totals['expense']), 'kelas' => 'keluar'],
    ['label' => 'Saldo akhir', 'nilai' => \App\Support\Rupiah::format($totals['balance']), 'kelas' => $totals['balance'] < 0 ? 'minus' : ''],
]])

{{-- Saldo per sumber dana.

     Dikumpulkan App\Reports\CashBook sambil baris dilipat, bukan lewat kueri
     agregat kedua: sebuah GROUP BY terpisah dijalankan atas himpunan baris
     yang bisa saja sudah berubah, lalu mencetak rekap yang jumlahnya tidak
     sama dengan baris-baris di bawahnya.

     Hanya dicetak bila ada lebih dari satu sumber. Dengan satu sumber, setiap
     angkanya sama persis dengan kartu ringkasan di atas, dan tabel yang hanya
     mengulang tidak layak menghabiskan tinggi halaman. --}}
@if (count($sources) > 1)
    <div class="subjudul">Saldo per sumber dana</div>

    <table class="buku rekap">
        <thead>
            <tr>
                <th style="width: 40%">Sumber</th>
                <th style="width: 20%" class="angka">Pemasukan</th>
                <th style="width: 20%" class="angka">Pengeluaran</th>
                <th style="width: 20%" class="angka">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sources as $sumber)
                <tr>
                    <td>{{ $sumber['name'] }}</td>
                    <td class="angka masuk">{{ \App\Support\Rupiah::format($sumber['income']) }}</td>
                    <td class="angka keluar">{{ \App\Support\Rupiah::format($sumber['expense']) }}</td>
                    <td class="angka @if ($sumber['balance'] < 0) minus @endif">{{ \App\Support\Rupiah::format($sumber['balance']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<table class="buku">
    {{-- dompdf mengulang <thead> di setiap halaman, jadi buku yang panjang
         tetap terbaca tanpa harus kembali ke halaman pertama. --}}
    <thead>
        <tr>
            <th style="width: 12%">Waktu</th>
            <th style="width: 20%">Keterangan</th>
            <th style="width: 11%">Sumber</th>
            <th style="width: 11%" class="angka">Pemasukan</th>
            <th style="width: 11%" class="angka">Pengeluaran</th>
            <th style="width: 11%" class="angka">Saldo</th>
            <th style="width: 15%">Bukti</th>
            <th style="width: 9%">Dicatat oleh</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($lines as $line)
            <tr>
                <td class="waktu">{{ $line['transaction']->occurred_at->translatedFormat('d M Y H:i') }}</td>
                <td>{{ $line['transaction']->description }}</td>
                {{-- Nama yang sudah dilipat laporan, bukan
                     $line['transaction']->source?->name: bagaimana baris tanpa
                     sumber dieja ditentukan sekali di CashBook, supaya kolom
                     ini dan rekap di atas tidak mengejanya dengan dua cara. --}}
                <td>{{ $line['source'] }}</td>
                <td class="angka masuk">{{ \App\Support\Rupiah::format($line['income']) }}</td>
                <td class="angka keluar">{{ \App\Support\Rupiah::format($line['expense']) }}</td>
                <td class="angka @if ($line['balance'] < 0) minus @endif">{{ \App\Support\Rupiah::format($line['balance']) }}</td>
                {{-- Foto kuitansinya, bukan jumlahnya. Relasi `media` sudah
                     di-eager-load dan sudah disaring ke koleksi ini oleh
                     CashBook::query(), jadi tidak ada kueri per baris. --}}
                <td>
                    @include('pdf.partials.bukti', [
                        'berkas' => \App\Support\PdfImage::paths(
                            $line['transaction']->media,
                            \App\Models\Transaction::RECEIPTS,
                            \App\Models\Transaction::THUMBNAIL,
                        ),
                        'batas' => 2,
                    ])
                </td>
                {{-- Sama dengan placeholder kolomnya di layar: user_id memang
                     boleh null, karena menghapus akun tidak boleh menghapus
                     catatan keuangan yang ditinggalkannya. --}}
                <td>{{ $line['transaction']->user?->name ?? 'Tidak diketahui' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="kosong">Belum ada transaksi untuk dicetak.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($totals['rows'] > 0)
        <tfoot>
            <tr>
                <td colspan="3">TOTAL</td>
                <td class="angka masuk">{{ \App\Support\Rupiah::format($totals['income']) }}</td>
                <td class="angka keluar">{{ \App\Support\Rupiah::format($totals['expense']) }}</td>
                <td class="angka @if ($totals['balance'] < 0) minus @endif">{{ \App\Support\Rupiah::format($totals['balance']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
