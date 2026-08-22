<?php

namespace App\Console\Commands;

use App\Jobs\ExportCashBook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes rendered exports once their signed links have expired.
 *
 * A finished export is a complete copy of the cash book on disk, reachable by
 * anyone holding its link for as long as that link is valid. Keeping the file
 * past that point protects nothing and accumulates a copy of the book per
 * download, forever.
 *
 * The cutoff is ExportCashBook::RETENTION_HOURS rather than a setting on
 * /monitoring, because it is not independent of the link: the same constant
 * sets how long temporaryUrl() signs for. Making the two settable apart would
 * be a way to produce a live link to a deleted file, or a file nothing will
 * ever remove.
 *
 * Unlike monitoring:prune this writes no activity entry per run. The file is a
 * derivative — every export already has its own transactions_exported entry
 * naming the file, and a second entry saying that copy was tidied up would
 * bury the one that says the book left the building.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Hapus berkas ekspor buku kas yang tautannya sudah kedaluwarsa';

    public function handle(): int
    {
        $disk = Storage::disk(ExportCashBook::DISK);

        $cutoff = now()->subHours(ExportCashBook::RETENTION_HOURS)->getTimestamp();

        $deleted = 0;

        foreach ($disk->files(ExportCashBook::DIRECTORY) as $file) {
            if ($disk->lastModified($file) > $cutoff) {
                continue;
            }

            $disk->delete($file);
            $deleted++;
        }

        $this->info($deleted === 0
            ? 'Tidak ada berkas ekspor yang kedaluwarsa.'
            : "{$deleted} berkas ekspor dihapus.");

        return self::SUCCESS;
    }
}
