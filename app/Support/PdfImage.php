<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns an attachment into something a PDF view can put in an `src`.
 *
 * ## Why a filesystem path and not a URL
 *
 * `enable_remote` is false (see the PDF section of CLAUDE.md), so dompdf will
 * not fetch `http://` at all — and it must not: a signed link to the private
 * disk goes back through the app to fetch a file the renderer is already
 * standing next to. Attachments live on the `local` disk, whose root is inside
 * `base_path()`, which is also dompdf's `chroot` — so the renderer can read
 * them straight off disk with no protocol, no request and no signature.
 *
 * A `data:` URI was the alternative and was rejected on memory. dompdf reads a
 * path lazily, one image at a time; base64 would put every photograph in a
 * report into one HTML string at four-thirds of its size, and a year of meter
 * readings is a few hundred photographs.
 *
 * ## Always the conversion, never the original
 *
 * `thumb` is re-encoded, and the re-encode is what drops the EXIF the phone
 * wrote — GPS coordinates included. An exported PDF is a file that leaves the
 * building, so handing it the original would put the coordinates of a building
 * with tenants in it into a document somebody emails on. See the EXIF note in
 * CLAUDE.md's Gotchas.
 *
 * It is also the only size worth embedding: the original is a phone photograph
 * at several megabytes, printed here about two centimetres wide.
 *
 * So a missing conversion yields null rather than falling back to the original.
 * That is a visible gap in one cell — the report prints a dash — where the
 * fallback would be a silent leak in every cell.
 */
class PdfImage
{
    /**
     * The absolute path dompdf should read, or null if there is nothing safe to
     * hand it.
     *
     * Null covers three cases that all have to fail the same quiet way, because
     * a report is rendered on a queue where there is nobody to tell: the
     * conversion was never generated, the file is gone from disk, or the disk
     * has been moved outside the project. dompdf answers all three by drawing
     * nothing at all and logging nothing — `show_warnings` is false — so the
     * check is here instead, where the view can print a dash.
     */
    public static function path(Media $media, string $conversion): ?string
    {
        if (! $media->hasGeneratedConversion($conversion)) {
            return null;
        }

        $path = $media->getPath($conversion);

        if (! is_file($path)) {
            return null;
        }

        // dompdf's chroot is base_path(). A path outside it is refused by the
        // renderer without an error, so it is refused here with one behaviour
        // the view can render.
        $root = realpath(base_path());
        $real = realpath($path);

        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * Every readable thumbnail in a collection, in the order they were
     * uploaded.
     *
     * Takes an already-loaded media relation rather than a model, so that
     * calling this per row costs no queries — the reports eager-load their
     * attachments for exactly this reason.
     *
     * @param  Collection<int, Media>  $media
     * @return array<int, string>
     */
    public static function paths(Collection $media, string $collection, string $conversion): array
    {
        return $media
            ->where('collection_name', $collection)
            ->map(fn (Media $item): ?string => self::path($item, $conversion))
            ->filter()
            ->values()
            ->all();
    }
}
