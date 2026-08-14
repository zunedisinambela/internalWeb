{{--
    Buku kas sebagai PDF.

    Dibangun dari tabel dan properti CSS 2.1 saja. dompdf tidak mengenal flexbox
    maupun grid, jadi apa pun yang disalin dari panel (Tailwind) akan tampil
    berantakan tanpa satu pun pesan kesalahan — `show_warnings` bernilai false.

    Semua teks dari pengguna ditulis dengan {{ }}, tidak pernah {!! !!}. Itu bukan
    kebiasaan kosong di sini: `chroot` dompdf adalah base_path() dan `file://`
    ada di allowed_protocols, sehingga markup yang lolos ke dalam dokumen bisa
    membaca berkas mana pun di proyek — .env termasuk, dan APP_KEY di dalamnya
    membuka secret dua faktor setiap pengguna. Lihat bagian PDF di CLAUDE.md.

    Footer dan nomor halaman tidak ada di sini. dompdf tidak punya counter
    `pages`, jadi counter(pages) CSS selalu mencetak 0, sementara $PAGE_COUNT
    menuntut `enable_php`. Keduanya dihindari: ExportTransactionsAction menulis
    footer lewat Canvas::page_text() setelah render.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Buku Kas</title>
    <style>
        @page {
            margin: 24mm 14mm 20mm 14mm;
        }

        body {
            /* `sans-serif` dipetakan dompdf ke Helvetica, bukan ke DejaVu —
               lihat lib/fonts/installed-fonts.dist.json. Itu font base-14, jadi
               tidak ada yang disematkan, tidak ada @font-face, dan storage/fonts
               tidak pernah disentuh: berkas empat baris keluar sekitar 2,8 KB.
               DejaVu Sans tersedia dengan menyebut namanya, tapi menyematkannya
               menambah ratusan kilobyte demi cakupan Unicode yang tidak dipakai
               dokumen berbahasa Indonesia. Tanda –, · dan — sudah ada di
               WinAnsi milik Helvetica. */
            font-family: sans-serif;
            font-size: 9pt;
            color: #111827;
            margin: 0;
        }

        .header {
            border-bottom: 1.5pt solid #111827;
            padding-bottom: 6pt;
            margin-bottom: 10pt;
        }

        .header h1 {
            font-size: 15pt;
            margin: 0 0 2pt 0;
        }

        .header .periode {
            font-size: 9pt;
            color: #4b5563;
        }

        /* Dicetak sebagai tabel, bukan float: dompdf menghitung tinggi float
           dengan cara yang mudah membuat blok berikutnya menimpanya. */
        .ringkasan {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10pt;
        }

        .ringkasan td {
            width: 33.33%;
            border: 0.75pt solid #d1d5db;
            padding: 6pt 8pt;
        }

        .ringkasan .label {
            font-size: 8pt;
            color: #6b7280;
            text-transform: uppercase;
        }

        .ringkasan .nilai {
            font-size: 12pt;
            font-weight: bold;
        }

        table.buku {
            width: 100%;
            border-collapse: collapse;
        }

        table.buku thead th {
            background-color: #f3f4f6;
            border-bottom: 1pt solid #9ca3af;
            padding: 5pt 6pt;
            font-size: 8.5pt;
            text-align: left;
        }

        table.buku tbody td {
            border-bottom: 0.5pt solid #e5e7eb;
            padding: 4pt 6pt;
            vertical-align: top;
        }

        table.buku tfoot td {
            border-top: 1pt solid #111827;
            padding: 6pt;
            font-weight: bold;
        }

        .angka {
            text-align: right;
            white-space: nowrap;
        }

        .tengah {
            text-align: center;
        }

        .waktu {
            white-space: nowrap;
        }

        .masuk {
            color: #047857;
        }

        .keluar {
            color: #b91c1c;
        }

        .minus {
            color: #b91c1c;
        }

        .kosong {
            padding: 18pt;
            text-align: center;
            color: #6b7280;
        }

    </style>
</head>
<body>

@php
    /**
     * Rupiah penuh, tanpa sen — sama seperti kolom di layar.
     *
     * Tanda minus ditaruh sebelum "Rp", bukan sesudahnya: number_format() saja
     * menghasilkan "Rp -1.830.000", yang membaca seperti mata uang bernama
     * "Rp -". Hanya saldo yang bisa negatif; kolom amount unsigned dan arah uang
     * disimpan di kolom type.
     *
     * Memakai hyphen ASCII, bukan U+2212 seperti di layar — tabel di sini
     * dicetak dengan Helvetica, dan U+2212 tidak ada di WinAnsi miliknya.
     */
    $rp = static function (?int $nilai): string {
        if ($nilai === null) {
            return '';
        }

        return ($nilai < 0 ? '-' : '').'Rp '.number_format(abs($nilai), 0, ',', '.');
    };
@endphp

<div class="header">
    <h1>Buku Kas</h1>
    <div class="periode">
        @if ($period)
            Periode {{ $period['from']->translatedFormat('d F Y') }}
            &ndash; {{ $period['until']->translatedFormat('d F Y') }}
            &middot; {{ $totals['rows'] }} transaksi
        @else
            Tidak ada transaksi pada rentang yang dipilih
        @endif
    </div>
</div>

<table class="ringkasan">
    <tr>
        <td>
            <div class="label">Total pemasukan</div>
            <div class="nilai masuk">{{ $rp($totals['income']) }}</div>
        </td>
        <td>
            <div class="label">Total pengeluaran</div>
            <div class="nilai keluar">{{ $rp($totals['expense']) }}</div>
        </td>
        <td>
            <div class="label">Saldo akhir</div>
            <div class="nilai @if ($totals['balance'] < 0) minus @endif">{{ $rp($totals['balance']) }}</div>
        </td>
    </tr>
</table>

<table class="buku">
    {{-- dompdf mengulang <thead> di setiap halaman, jadi buku yang panjang
         tetap terbaca tanpa harus kembali ke halaman pertama. --}}
    <thead>
        <tr>
            <th style="width: 15%">Waktu</th>
            <th style="width: 33%">Keterangan</th>
            <th style="width: 13%" class="angka">Pemasukan</th>
            <th style="width: 13%" class="angka">Pengeluaran</th>
            <th style="width: 13%" class="angka">Saldo</th>
            <th style="width: 5%" class="tengah">Bukti</th>
            <th style="width: 8%">Dicatat oleh</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($lines as $line)
            <tr>
                <td class="waktu">{{ $line['transaction']->occurred_at->translatedFormat('d M Y H:i') }}</td>
                <td>{{ $line['transaction']->description }}</td>
                <td class="angka masuk">{{ $rp($line['income']) }}</td>
                <td class="angka keluar">{{ $rp($line['expense']) }}</td>
                <td class="angka @if ($line['balance'] < 0) minus @endif">{{ $rp($line['balance']) }}</td>
                <td class="tengah">{{ $line['receipts'] }}</td>
                {{-- Sama dengan placeholder kolomnya di layar: user_id memang
                     boleh null, karena menghapus akun tidak boleh menghapus
                     catatan keuangan yang ditinggalkannya. --}}
                <td>{{ $line['transaction']->user?->name ?? 'Tidak diketahui' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="kosong">Belum ada transaksi untuk dicetak.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($totals['rows'] > 0)
        <tfoot>
            <tr>
                <td colspan="2">TOTAL</td>
                <td class="angka masuk">{{ $rp($totals['income']) }}</td>
                <td class="angka keluar">{{ $rp($totals['expense']) }}</td>
                <td class="angka @if ($totals['balance'] < 0) minus @endif">{{ $rp($totals['balance']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
