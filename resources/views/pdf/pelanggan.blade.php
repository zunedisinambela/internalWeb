{{--
    Pelanggan sebagai PDF.

    Berkas ini memuat alamat rumah orang. Itu paparan yang sama dengan yang
    dijaga panel di balik View:Customer, hanya dalam bentuk yang meninggalkan
    gedung — lihat docs/access-control.md. Itulah sebabnya unduhan ini
    diotorisasi lewat resource dan dicatat di activity_log oleh job-nya.

    Tidak ada kolom uang. Keuntungan dari seorang pelanggan dijumlahkan di layar
    pelanggan itu sendiri dengan menelusuri pesanannya di PHP; memintanya di
    sini berarti menyalin aritmetika yang sama sebagai SQL, dan dua angka yang
    bisa saling bertentangan.

    Teks pengguna ditulis dengan {{ }}, tidak pernah {!! !!} — lihat bagian PDF
    di CLAUDE.md.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pelanggan</title>
    <style>
        @include('pdf.partials.gaya')
    </style>
</head>
<body>

@include('pdf.partials.kop', [
    'judul' => 'Pelanggan',
    'jumlah' => $totals['rows'],
    'ringkas' => [$totals['rows'].' pelanggan'],
])

@include('pdf.partials.ringkasan', ['kartu' => [
    ['label' => 'Jumlah pelanggan', 'nilai' => number_format($totals['rows'], 0, ',', '.')],
    ['label' => 'Total barang', 'nilai' => number_format($totals['quantity'], 0, ',', '.')],
    ['label' => 'Gratis didapat', 'nilai' => number_format($totals['earned'], 0, ',', '.')],
    ['label' => 'Sisa gratis', 'nilai' => number_format($totals['outstanding'], 0, ',', '.'), 'kelas' => $totals['outstanding'] < 0 ? 'minus' : ''],
]])

<table class="buku">
    <thead>
        <tr>
            <th style="width: 16%">Nama</th>
            <th style="width: 11%">Telepon</th>
            <th style="width: 20%">Alamat</th>
            <th style="width: 7%" class="tengah">Transaksi</th>
            <th style="width: 7%" class="tengah">Barang</th>
            <th style="width: 8%" class="tengah">Gratis didapat</th>
            <th style="width: 8%" class="tengah">Gratis diambil</th>
            <th style="width: 7%" class="tengah">Sisa</th>
            <th style="width: 8%">Status</th>
            <th style="width: 8%">Ditambahkan</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($lines as $line)
            @php $customer = $line['customer']; @endphp
            <tr>
                <td>
                    {{ $customer->name }}
                    @if (filled($customer->note))
                        <div class="samar">{{ $customer->note }}</div>
                    @endif
                </td>
                <td>{{ $customer->phone }}</td>
                <td>{{ $customer->address }}</td>
                <td class="tengah">{{ number_format($line['orders'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($line['quantity'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($line['earned'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($line['claimed'], 0, ',', '.') }}</td>
                {{-- Tidak dijepit ke nol: nilai negatif berarti sebuah serah
                     terima tercatat atas pesanan yang kemudian dikoreksi turun,
                     dan itu masalah pembukuan yang harus terlihat. Lihat
                     Customer::$free_quantity_available. --}}
                <td class="tengah @if ($line['outstanding'] < 0) minus @endif">{{ number_format($line['outstanding'], 0, ',', '.') }}</td>
                <td>{{ $customer->is_active ? 'Aktif' : 'Tidak aktif' }}</td>
                <td class="waktu">{{ $customer->created_at?->translatedFormat('d M Y') }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="kosong">Belum ada pelanggan untuk dicetak.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($totals['rows'] > 0)
        <tfoot>
            <tr>
                <td colspan="3">TOTAL</td>
                <td class="tengah">{{ number_format($totals['orders'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($totals['quantity'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($totals['earned'], 0, ',', '.') }}</td>
                <td class="tengah">{{ number_format($totals['claimed'], 0, ',', '.') }}</td>
                <td class="tengah @if ($totals['outstanding'] < 0) minus @endif">{{ number_format($totals['outstanding'], 0, ',', '.') }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
