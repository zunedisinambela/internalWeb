{{--
    Satu sel berisi lampiran sebuah baris.

    $berkas adalah daftar path absolut yang sudah diperiksa oleh
    App\Support\PdfImage — konversi `thumb`, ada di disk, dan berada di dalam
    chroot dompdf. Tampilan ini tidak memutuskan apa pun soal itu; ia hanya
    mencetak apa yang sudah aman dicetak.

    $batas membatasi berapa gambar yang muat dalam satu sel. Sisanya dihitung
    dan dicetak sebagai "+n", tidak pernah dibuang diam-diam: laporan yang
    memangkas isinya tanpa mengatakannya terbaca seolah itulah seluruh isinya.
--}}
@php
    $tampil = array_slice($berkas, 0, $batas ?? 4);
    $sisa = count($berkas) - count($tampil);
@endphp

@forelse ($tampil as $path)
    <img class="bukti" src="{{ $path }}" alt="">
@empty
    <span class="samar">&ndash;</span>
@endforelse

@if ($sisa > 0)
    <span class="bukti-lebih">+{{ $sisa }}</span>
@endif
