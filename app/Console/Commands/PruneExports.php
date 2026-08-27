<?php

namespace App\Console\Commands;

use App\Jobs\ExportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes rendered exports once their signed links have expired.
 *
 * A finished export is a complete copy of a list screen on disk, reachable by
 * anyone holding its link for as long as that link is valid. Keeping the file
 * past that point protects nothing and accumulates a copy per download, forever.
 *
 * One directory holds all four reports and one cutoff applies to all of them,
 * because the cutoff is not a property of what was exported — it is how long the
 * signature on the link stays valid.
 *
 * The cutoff is ExportReport::RETENTION_HOURS rather than a setting on
 * /monitoring, because it is not independent of the link: the same constant
 * sets how long temporaryUrl() signs for. Making the two settable apart would
 * be a way to produce a live link to a deleted file, or a file nothing will
 * ever remove.
 *
 * Unlike monitoring:prune this writes no activity entry per run. The file is a
 * derivative — every export already has its own `*_exported` entry naming the
 * file, and a second entry saying that copy was tidied up would bury the one
 * that says the data left the building.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Hapus berkas ekspor yang tautannya sudah kedaluwarsa';

    public function handle(): int
    {
        $disk = Storage::disk(ExportReport::DISK);

        $cutoff = now()->subHours(ExportReport::RETENTION_HOURS)->getTimestamp();

        $deleted = 0;

        foreach ($disk->files(ExportReport::DIRECTORY) as $file) {
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
