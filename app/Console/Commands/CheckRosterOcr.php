<?php

namespace App\Console\Commands;

use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use Illuminate\Console\Command;

/**
 * Measures OCR accuracy by running the real reader over a fixture directory of
 * screenshots and diffing the result against its expected members.json. This is
 * the go/no-go signal for whether the current OCR backend is good enough.
 */
class CheckRosterOcr extends Command
{
    protected $signature = 'roster:ocr-check {dir=tests/stubs/team : Directory of *.png screenshots and a members.json}';

    protected $description = 'Run roster OCR over a fixture directory and report accuracy against members.json';

    public function handle(RosterScreenshotReader $reader): int
    {
        $dir = (string) $this->argument('dir');
        $images = glob($dir.'/*.png') ?: [];
        sort($images, SORT_NATURAL);
        $expectedFile = $dir.'/members.json';

        if ($images === [] || ! is_file($expectedFile)) {
            $this->error("Expected *.png screenshots and members.json in [{$dir}].");

            return self::FAILURE;
        }

        $expected = collect(json_decode((string) file_get_contents($expectedFile), true)['members'] ?? [])
            ->mapWithKeys(fn (array $row): array => [mb_strtolower($row['name']) => $row['position']]);

        $this->info(sprintf('Reading %d screenshot(s) from %s …', count($images), $dir));
        $parsed = collect($reader->read($images))
            ->mapWithKeys(fn (array $row): array => [mb_strtolower($row['name']) => $row]);

        $matched = 0;
        $wrongPosition = 0;
        $missing = [];

        foreach ($expected as $key => $position) {
            $row = $parsed->get($key);

            if ($row === null) {
                $missing[] = $key;

                continue;
            }

            $row['position'] === $position ? $matched++ : $wrongPosition++;
        }

        $extra = $parsed->keys()->diff($expected->keys());

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Expected members', $expected->count()],
            ['Parsed members', $parsed->count()],
            ['Exact matches (name + position)', $matched],
            ['Name match, wrong position', $wrongPosition],
            ['Missing (not parsed)', count($missing)],
            ['Extra (not expected)', $extra->count()],
        ]);

        $accuracy = $expected->count() > 0 ? round($matched / $expected->count() * 100, 1) : 0.0;
        $this->newLine();
        $this->line("<options=bold>Accuracy: {$accuracy}%</> (exact matches / expected)");

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
