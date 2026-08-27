{{--
    Kepala laporan: judul, lalu periode yang benar-benar tercetak.

    $period berasal dari baris yang dilipat, bukan dari filter yang memilihnya —
    lihat App\Reports\Report::period() untuk alasannya. Null berarti tidak ada
    baris, dan itu harus berbunyi lain daripada rentang tanggal kosong.

    $ringkas adalah daftar potongan teks, bukan satu string. Pemisahnya —
    &middot; — adalah markup, dan setiap potongan dicetak lewat {{ }} yang
    meng-escape entity: sebuah laporan yang menyusun "2 penjualan &middot; 30
    barang" di PHP akan mencetak entitasnya apa adanya di halaman. Jadi
    pemisahnya ditulis di sini, di tempat ia memang markup.
--}}
<div class="header">
    <h1>{{ $judul }}</h1>
    <div class="periode">
        @if ($period)
            Periode {{ $period['from']->translatedFormat('d F Y') }}
            &ndash; {{ $period['until']->translatedFormat('d F Y') }}
            @foreach ((array) $ringkas as $bagian)
                &middot; {{ $bagian }}
            @endforeach
        @elseif ($jumlah > 0)
            @foreach ((array) $ringkas as $i => $bagian)
                @if ($i) &middot; @endif {{ $bagian }}
            @endforeach
        @else
            Tidak ada data pada rentang yang dipilih
        @endif
    </div>
</div>
