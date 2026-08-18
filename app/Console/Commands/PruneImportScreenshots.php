<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('imports:prune-screenshots {--hours=24 : Keep screenshots newer than this many hours}')]
#[Description('Delete uploaded VS score screenshots and abandoned Livewire uploads from the default disk')]
class PruneImportScreenshots extends Command
{
    /**
     * The directories on the default disk that accumulate uploaded screenshots.
     *
     * `livewire-tmp` holds chunks from uploads that were never submitted, which
     * Livewire only sweeps when the storage backend does it on a lifecycle rule.
     *
     * @var array<int, string>
     */
    private const DIRECTORIES = ['imports', 'livewire-tmp'];

    /**
     * Delete screenshots that OCR has long since finished with.
     *
     * Nothing else ever removes them, so the disk grows by roughly half a megabyte per
     * screenshot forever. The parsed rows live on the import record, so the images are
     * only needed for the length of the OCR run — a day of grace is generous.
     */
    public function handle(): int
    {
        $disk = Storage::disk();
        $cutoff = CarbonImmutable::now()->subHours((int) $this->option('hours'));
        $deleted = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach ($disk->files($directory) as $path) {
                if ($cutoff->getTimestamp() <= $disk->lastModified($path)) {
                    continue;
                }

                $disk->delete($path);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} screenshot(s) older than {$cutoff->diffForHumans()}.");

        return self::SUCCESS;
    }
}
