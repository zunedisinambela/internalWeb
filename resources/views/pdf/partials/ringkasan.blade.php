{{--
    Kartu-kartu angka di atas tabel.

    $kartu adalah daftar ['label' => string, 'nilai' => string, 'kelas' => ?string].
    Lebar dibagi rata di sini, bukan di CSS, supaya tiga kartu dan empat kartu
    sama-sama memenuhi baris tanpa kelas tambahan per laporan.
--}}
<table class="ringkasan">
    <tr>
        @foreach ($kartu as $k)
            <td style="width: {{ round(100 / max(1, count($kartu)), 4) }}%">
                <div class="label">{{ $k['label'] }}</div>
                <div class="nilai {{ $k['kelas'] ?? '' }}">{{ $k['nilai'] }}</div>
            </td>
        @endforeach
    </tr>
</table>
