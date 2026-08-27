{{--
    Meteran listrik sebagai PDF.

    Dua angka meteran dicetak berdampingan dengan fotonya masing-masing. Itulah
    sebabnya foto disimpan dalam dua koleksi terpisah: tagihan yang
    dipersoalkan diselesaikan dengan membandingkan angka pembukaan terhadap foto
    yang diambil saat periode dibuka, dan satu koleksi hanya bisa menyatakan
    pasangan itu lewat urutan unggah — yang hilang begitu satu berkas dihapus.

    Tidak ada kolom tarif dan tidak ada baris tarif. Panel mencatat tagihan,
    bukan menghitungnya; membagi tagihan dengan pemakaian di sini berarti
    menyimpulkan angka yang sengaja tidak disimpan proyek ini. Lihat
    docs/listrik-kost.md.

    Teks pengguna ditulis dengan {{ }}, tidak pernah {!! !!} — lihat bagian PDF
    di CLAUDE.md.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Meteran Listrik</title>
    <style>
        @include('pdf.partials.gaya')
    </style>
</head>
<body>

@include('pdf.partials.kop', [
    'judul' => 'Meteran Listrik',
    'jumlah' => $totals['rows'],
    'ringkas' => [$totals['rows'].' pembacaan'],
])

@include('pdf.partials.ringkasan', ['kartu' => [
    ['label' => 'Total pemakaian', 'nilai' => number_format($totals['usage'], 0, ',', '.').' kWh', 'kelas' => $totals['usage'] < 0 ? 'minus' : ''],
    ['label' => 'Total tagihan', 'nilai' => \App\Support\Rupiah::format($totals['amount'])],
    ['label' => 'Jumlah pembacaan', 'nilai' => number_format($totals['rows'], 0, ',', '.')],
]])

<table class="buku">
    <thead>
        <tr>
            <th style="width: 12%">Pembacaan awal</th>
            <th style="width: 12%">Pembacaan akhir</th>
            <th style="width: 8%" class="angka">kWh awal</th>
            <th style="width: 8%" class="angka">kWh akhir</th>
            <th style="width: 9%" class="angka">Pemakaian</th>
            <th style="width: 12%" class="angka">Tagihan</th>
            <th style="width: 9%">Foto awal</th>
            <th style="width: 9%">Foto akhir</th>
            <th style="width: 12%">Catatan</th>
            <th style="width: 9%">Dicatat oleh</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($lines as $line)
            @php $reading = $line['reading']; @endphp
            <tr>
                <td class="waktu">{{ $reading->start_read_at->translatedFormat('d M Y H:i') }}</td>
                <td class="waktu">{{ $reading->end_read_at->translatedFormat('d M Y H:i') }}</td>
                <td class="angka">{{ number_format($reading->start_kwh, 0, ',', '.') }}</td>
                <td class="angka">{{ number_format($reading->end_kwh, 0, ',', '.') }}</td>
                {{-- Tidak dijepit ke nol, sama seperti MeterReading::$usage_kwh:
                     angka negatif hanya bisa datang dari baris yang ditulis di
                     luar formulir, dan mencetaknya merah adalah cara itu
                     terlihat. --}}
                <td class="angka @if ($line['usage'] < 0) minus @endif">{{ number_format($line['usage'], 0, ',', '.') }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($reading->total_amount) }}</td>
                <td>
                    @include('pdf.partials.bukti', [
                        'berkas' => \App\Support\PdfImage::paths($reading->media, \App\Models\MeterReading::PHOTOS_START, \App\Models\MeterReading::THUMBNAIL),
                        'batas' => 2,
                    ])
                </td>
                <td>
                    @include('pdf.partials.bukti', [
                        'berkas' => \App\Support\PdfImage::paths($reading->media, \App\Models\MeterReading::PHOTOS_END, \App\Models\MeterReading::THUMBNAIL),
                        'batas' => 2,
                    ])
                </td>
                <td>{{ $reading->note }}</td>
                <td>{{ $reading->user?->name ?? 'Tidak diketahui' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" class="kosong">Belum ada pembacaan meteran untuk dicetak.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($totals['rows'] > 0)
        <tfoot>
            <tr>
                {{-- Kolom kWh awal dan akhir tidak dijumlahkan: keduanya posisi
                     jarum, dan kolom posisi tidak punya total. --}}
                <td colspan="4">TOTAL</td>
                <td class="angka @if ($totals['usage'] < 0) minus @endif">{{ number_format($totals['usage'], 0, ',', '.') }}</td>
                <td class="angka">{{ \App\Support\Rupiah::format($totals['amount']) }}</td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
