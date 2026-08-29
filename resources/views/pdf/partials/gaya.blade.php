{{--
    Gaya bersama untuk seluruh laporan PDF.

    Dibangun dari tabel dan properti CSS 2.1 saja. dompdf tidak mengenal flexbox
    maupun grid, jadi apa pun yang disalin dari panel (Tailwind) akan tampil
    berantakan tanpa satu pun pesan kesalahan — `show_warnings` bernilai false.

    Satu berkas untuk empat laporan: bila tiap tampilan menyalin gayanya
    sendiri, satu laporan akan menyimpang dari yang lain pada perubahan
    berikutnya, dan hasilnya bukan galat melainkan dokumen yang terlihat asing.
--}}
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

/* Rekap kecil di atas buku utama — dipakai buku kas untuk saldo per sumber
   dana. Memakai ulang gaya table.buku alih-alih menyalinnya: yang berbeda
   hanya jaraknya ke tabel di bawahnya. */
.subjudul {
    font-size: 9.5pt;
    font-weight: bold;
    margin: 0 0 4pt 0;
}

table.rekap {
    margin-bottom: 12pt;
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

.samar {
    color: #6b7280;
}

.kosong {
    padding: 18pt;
    text-align: center;
    color: #6b7280;
}

/* Lampiran bukti.

   Lebar dipatok, tinggi dibiarkan mengikuti rasio: konversi `thumb` memakai
   Fit::Contain, jadi foto potret dan lanskap punya rasio berbeda dan memaksa
   keduanya ke satu kotak akan menggepengkan salah satunya.

   dompdf tidak mengenal `object-fit`. Itu bukan sesuatu yang bisa ditambal di
   sini — jangan menyalinnya dari panel. */
img.bukti {
    width: 46pt;
    margin: 0 2pt 2pt 0;
    border: 0.5pt solid #d1d5db;
}

.bukti-lebih {
    font-size: 7.5pt;
    color: #6b7280;
    white-space: nowrap;
}
