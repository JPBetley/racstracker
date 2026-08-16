<?php

namespace App\Imports\Ocr;

use App\Ai\Agents\VsScoreScreenshotExtractor;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Normalizer;

/**
 * Reads VS "Weekly Rank" screenshots with a vision model via the Laravel AI SDK.
 *
 * The provider and model come from config/vs.php, so this works with a paid,
 * high-accuracy model (Claude) or a free one (a local Ollama model, or Gemini's free
 * tier) by changing .env only.
 *
 * Cloud models read all screenshots in one prompt and de-duplicate the overlapping
 * scroll captures themselves. Small local (Ollama) models lose recall when handed
 * many images at once, so each screenshot is read in its own request and the rows
 * are merged here. Rows are cleaned, parsed and de-duplicated again as defensive
 * insurance: the pinned "your row" repeats on every capture and edge rows are
 * routinely clipped, so the prompt alone is not trusted to produce a clean list.
 */
class AiVisionVsScoreScreenshotReader implements VsScoreScreenshotReader
{
    public function read(array $imagePaths): array
    {
        if ($imagePaths === []) {
            return [];
        }

        $provider = Lab::from(config('vs.ocr.provider'));
        $model = config('vs.ocr.model');
        $paths = array_values($imagePaths);

        // Local models read one screenshot at a time; cloud models take them all at once.
        $batches = $provider === Lab::Ollama
            ? array_map(fn (string $path): array => [$path], $paths)
            : [$paths];

        $rows = [];

        foreach ($batches as $batch) {
            $response = (new VsScoreScreenshotExtractor)->prompt(
                'Extract the weekly VS leaderboard from these ranking screenshots.',
                attachments: array_map(fn (string $path) => Files\Image::fromPath($path), $batch),
                provider: $provider,
                model: $model,
            );

            foreach ($response['rows'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $this->normalise($rows);
    }

    /**
     * Clean names, parse point totals, drop unreadable rows, and de-duplicate by name.
     *
     * Clipped rows are dropped before de-duplication, so the surviving copy of a
     * commander who was cut off in one capture is always the fully readable one from
     * a neighbouring capture.
     *
     * @param  array<int, array{rank?: int, name?: string, points?: ?string}>  $rows
     * @return array<int, array{rank: int, name: string, points: int}>
     */
    private function normalise(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $name = $this->cleanName($row['name'] ?? '');
            $points = $this->parsePoints($row['points'] ?? null);

            if ($name === '' || $points === null) {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['rank' => (int) ($row['rank'] ?? 0), 'name' => $name, 'points' => $points];
        }

        usort($result, fn (array $a, array $b): int => $b['points'] <=> $a['points']
            ?: strcasecmp($a['name'], $b['name']));

        return $result;
    }

    /**
     * Parse a comma-grouped points string into an integer, or null when unreadable.
     *
     * A member who scored nothing is a legitimate zero, so only an absent or
     * digit-free value counts as unreadable.
     */
    private function parsePoints(mixed $value): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits === '' ? null : (int) $digits;
    }

    /**
     * Strip non-Latin characters and normalise whitespace in a name.
     *
     * Decomposing to NFD first splits a decorated Latin letter into its base letter
     * plus a combining mark, so only the mark is stripped and the letter survives:
     * "Bęęfaronį" cleans to "Beefaroni" rather than "Bfaron". Scripts with no Latin
     * base — Cyrillic, CJK, Hangul — do not decompose and are dropped as before.
     * This matters because the cleaned name is what RosterNameMatcher matches on, and
     * a name gutted to "Bfaron" falls under its similarity threshold.
     */
    private function cleanName(string $text): string
    {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        $ascii = preg_replace('/[^\x20-\x7E]/u', '', $decomposed) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }
}
