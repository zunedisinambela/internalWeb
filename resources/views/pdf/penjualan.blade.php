{{--
    Penjualan sebagai PDF.

    Semua teks dari pengguna — nama pelanggan, catatan, dan path berkas di
    kolom bukti — ditulis dengan {{ }}, tidak pernah {!! !!}. `chroot` dompdf
    adalah base_path() dan `file://` ada di allowed_protocols, jadi markup yang
    lolos ke dokumen bisa membaca berkas mana pun di proyek. Lihat bagian PDF di
    CLAUDE.md.

    Dua kolom bukti, bukan satu. Sebuah berkas sudah menyatakan dirinya bukti
    transfer atau resi lewat collection_name — menggabung keduanya di satu sel
    akan membuang perbedaan itu, dan resi adalah satu-satunya lampiran yang
    memuat alamat rumah pelanggan.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Penjualan</title>
    <style>
        @include('pdf.partials.gaya')
    </style>
</head>
<body>

@include('pdf.partials.kop', [
    'judul' => 'Penjualan',
    'jumlah' => $totals['rows'],
    'ringkas' => [
        $totals['rows'].' penjualan',
        number_format($totals['quantity'], 0, ',', '.').' barang',
    ],
])

@include('pdf.partials.ringkasan', ['kartu' => [
    ['label' => 'Total harga katalog', 'nilai' => \App\Support\Rupiah::format($totals['catalog'])],
    ['label' => 'Total harga market', 'nilai' => \App\Support\Rupiah::format($totals['marketing'])],
    ['label' => 'Total ongkir', 'nilai' => \App\Support\Rupiah::format($totals['shipping'])],
    ['label' => 'Total keuntungan', 'nilai' => \App\Support\Rupiah::format($totals['profit']), 'kelas' => $totals['profit'] < 0 ? 'minus' : 'masuk'],
]])

<table class="buku">
    <thead>
        <tr>
            <th style="width: 9%">Tanggal</th>
            <th style="width: 18%">Pelanggan</th>
            <th style="width: 6%" class="tengah">Jumlah</th>
            <th style="width: 11%" class="angka">Harga market</th>
            <th style="width: 9%" class="angka">Ongkir</th>
            <th style="width: 11%" class="angka">Harga katalog</th>
            <th style="width: 11%" class="angka">Keuntungan</th>
            <th style="width: 8%">Bukti transfer</th>
            <th style="width: 8%">Resi</th>
            <th style="width: 9%">Dicatat oleh</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($lines as $line)
            @php $sale = $line['sale']; @endphp
            <tr>
                <td class="waktu">{{ $sale->occurred_at->translatedFormat('d M Y H:i') }}</td>
                <td>
                    {{-- Pelanggan boleh saja terhapus; pesanannya tidak ikut
                         hilang, jadi kolom ini harus punya bunyi untuk itu. --}}
                    {{ $sale->customer?->name ?? 'Tidak diketahui' }}
                    @if (filled($sale->note))
                        {{-- Catatan menempel pada nama, bukan kolom sendiri:
                             satu kolom yang hampir selalu kosong memakan lebar
                             halaman untuk tidak mengatakan apa-apa. --}}
                        <div class="samar">{{ $sale->note }}</div>
                    @endif
                </td>
                <td class="tengah">{{ number_format($sale->quantity, 0, ',', '.') }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($sale->marketing_price) }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($sale->shipping_cost) }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($sale->catalog_price) }}</td>
                <td class="angka @if ($line['profit'] < 0) minus @else masuk @endif">{{ \App\Support\Rupiah::format($line['profit']) }}</td>
                <td>
                    @include('pdf.partials.bukti', [
                        'berkas' => \App\Support\PdfImage::paths($sale->media, \App\Models\Sale::PAYMENT_PROOFS, \App\Models\Sale::THUMBNAIL),
                        'batas' => 2,
                    ])
                </td>
                <td>
                    @include('pdf.partials.bukti', [
                        'berkas' => \App\Support\PdfImage::paths($sale->media, \App\Models\Sale::SHIPPING_PROOFS, \App\Models\Sale::THUMBNAIL),
                        'batas' => 2,
                    ])
                </td>
                <td>{{ $sale->user?->name ?? 'Tidak diketahui' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="kosong">Belum ada penjualan untuk dicetak.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($totals['rows'] > 0)
        <tfoot>
            <tr>
                <td colspan="2">TOTAL</td>
                <td class="tengah">{{ number_format($totals['quantity'], 0, ',', '.') }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($totals['marketing']) }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($totals['shipping']) }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($totals['catalog']) }}</td>
                <td class="angka @if ($totals['profit'] < 0) minus @else masuk @endif">{{ \App\Support\Rupiah::format($totals['profit']) }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
