<?php

namespace App\Imports\Ocr;

use App\Ai\Agents\VsScoreScreenshotExtractor;
use App\Imports\Ocr\Contracts\VsScoreScreenshotReader;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;
use Normalizer;

/**
 * Reads VS ranking screenshots with Claude via the Laravel AI SDK.
 *
 * Every screenshot goes into a single prompt, so the model can de-duplicate the
 * overlapping scroll captures itself. The model to use comes from config/vs.php.
 *
 * The returned rows are still cleaned, parsed and de-duplicated here, because the
 * screen itself is adversarial rather than the model being weak: the pinned "your
 * row" appears on every capture with an out-of-sequence rank, and rows at the top
 * and bottom edges are routinely clipped mid-number.
 */
class AiVisionVsScoreScreenshotReader implements VsScoreScreenshotReader
{
    public function read(array $imagePaths): array
    {
        if ($imagePaths === []) {
            return [];
        }

        $response = (new VsScoreScreenshotExtractor)->prompt(
            'Extract the VS leaderboard from these ranking screenshots.',
            attachments: array_map(
                fn (string $path) => Files\Image::fromPath($path),
                array_values($imagePaths),
            ),
            provider: Lab::Anthropic,
            model: config('vs.ocr.model'),
        );

        $rows = $this->normalise($response['rows'] ?? []);

        Log::debug('VS OCR read screenshots.', [
            'screenshots' => count($imagePaths),
            'rows' => count($rows),
        ]);

        // An empty read is indistinguishable from a clean run downstream, so capture
        // what the model actually said rather than leaving it to be guessed at later.
        if ($rows === []) {
            Log::debug('VS OCR returned no rows.', ['raw' => $response['rows'] ?? null]);
        }

        return $rows;
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
