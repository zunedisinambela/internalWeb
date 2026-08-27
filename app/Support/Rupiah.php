<?php

namespace App\Support;

/**
 * Whole rupiah as a printed string, for the PDF reports.
 *
 * Four report views print money and they have to agree, so the formatting is
 * here rather than repeated as an @php closure at the top of each one.
 *
 * Two details that look like nothing and are not:
 *
 * The minus sign goes before the "Rp", not after it. number_format() alone
 * gives "Rp -1.830.000", which reads like a currency named "Rp -".
 *
 * It is an ASCII hyphen rather than the U+2212 the panel uses on screen. These
 * tables are printed in Helvetica — a base-14 font, so nothing is embedded and
 * the file stays small (see docs/pdf.md) — and U+2212 is not in its WinAnsi
 * encoding. dompdf drops a missing glyph silently, so the wrong dash here is a
 * figure that prints as a positive number.
 */
class Rupiah
{
    /**
     * Null prints as an empty cell rather than as Rp 0.
     *
     * Both renderers depend on the distinction: in the cash book an empty cell
     * means "not this side of the book", where a zero means a transaction that
     * moved nothing.
     */
    public static function format(?int $value): string
    {
        if ($value === null) {
            return '';
        }

        return ($value < 0 ? '-' : '').'Rp '.number_format(abs($value), 0, ',', '.');
    }
}
