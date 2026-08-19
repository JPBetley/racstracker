<?php

namespace App\Console\Commands;

use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use Illuminate\Console\Command;

/**
 * Measures VS score OCR accuracy by running the real reader over a fixture directory
 * of "Weekly Rank" screenshots and diffing the result against its expected scores.json.
 * This is the go/no-go signal for whether the current OCR backend can be trusted with
 * nine-digit point totals, where one wrong digit is a silently wrong score.
 *
 * To measure a different backend, edit the provider chain in
 * AiVisionVsScoreScreenshotReader and run this again.
 */
class CheckVsScoreOcr extends Command
{
    protected $signature = 'vs:ocr-check
        {dir=tests/stubs/vs : Directory of *.png screenshots and a scores.json}';

    protected $description = 'Run VS score OCR over a fixture directory and report accuracy against scores.json';

    public function handle(VsScoreScreenshotReader $reader): int
    {
        $dir = (string) $this->argument('dir');
        $images = glob($dir.'/*.png') ?: [];
        sort($images, SORT_NATURAL);
        $expectedFile = $dir.'/scores.json';

        if ($images === [] || ! is_file($expectedFile)) {
            $this->error("Expected *.png screenshots and scores.json in [{$dir}].");

            return self::FAILURE;
        }

        $expected = collect(json_decode((string) file_get_contents($expectedFile), true)['scores'] ?? [])
            ->mapWithKeys(fn (array $row): array => [mb_strtolower($row['name']) => (int) $row['points']]);

        $this->info(sprintf('Reading %d screenshot(s) from %s …', count($images), $dir));

        // The reader reads from a disk, not from the filesystem, so the fixture directory
        // is registered as one for the run. This keeps the harness on the exact code path
        // production uses rather than a local-path shortcut that only exists here.
        config()->set('filesystems.disks.vs-fixtures', ['driver' => 'local', 'root' => realpath($dir)]);

        $startedAt = microtime(true);
        $parsed = collect($reader->read(array_map(basename(...), $images), 'vs-fixtures'))
            ->mapWithKeys(fn (array $row): array => [mb_strtolower($row['name']) => $row]);
        $elapsed = microtime(true) - $startedAt;

        $matched = 0;
        $wrongPoints = [];
        $missing = [];

        foreach ($expected as $key => $points) {
            $row = $parsed->get($key);

            if ($row === null) {
                $missing[] = $key;

                continue;
            }

            if ($row['points'] === $points) {
                $matched++;

                continue;
            }

            $wrongPoints[] = sprintf(
                '%s: expected %s → parsed %s',
                $key,
                number_format($points),
                number_format($row['points']),
            );
        }

        $extra = $parsed->keys()->diff($expected->keys());

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Expected commanders', $expected->count()],
            ['Parsed commanders', $parsed->count()],
            ['Exact matches (name + points)', $matched],
            ['Name match, wrong points', count($wrongPoints)],
            ['Missing (not parsed)', count($missing)],
            ['Extra (not expected)', $extra->count()],
            // A free model is only the better trade if it is not unusably slow.
            ['Elapsed (seconds)', round($elapsed, 1)],
        ]);

        $accuracy = $expected->count() > 0 ? round($matched / $expected->count() * 100, 1) : 0.0;
        $this->newLine();
        $this->line("<options=bold>Accuracy: {$accuracy}%</> (exact matches / expected)");

        // A bare percentage cannot tell a dropped digit from a fumbled name, so every
        // mismatched total is printed in full.
        if ($wrongPoints !== []) {
            $this->newLine();
            $this->warn('Wrong points:');
            foreach ($wrongPoints as $line) {
                $this->warn('  '.$line);
            }
        }

        if ($missing !== []) {
            $this->newLine();
            $this->warn('Missing names: '.implode(', ', $missing));
        }

        if ($extra->isNotEmpty()) {
            $this->newLine();
            $this->warn('Extra names: '.$extra->implode(', '));
        }

        return self::SUCCESS;
    }
}
