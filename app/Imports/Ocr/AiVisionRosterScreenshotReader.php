<?php

namespace App\Imports\Ocr;

use App\Ai\Agents\RosterScreenshotExtractor;
use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;

/**
 * Reads alliance roster screenshots with Claude via the Laravel AI SDK.
 *
 * Every screenshot goes into a single prompt, so the model can de-duplicate the
 * overlapping scroll captures itself. The model to use comes from config/roster.php.
 *
 * Names are still cleaned and de-duplicated here as defensive insurance, since the
 * same member necessarily appears in several overlapping captures.
 */
class AiVisionRosterScreenshotReader implements RosterScreenshotReader
{
    private const VALID_POSITIONS = ['R5', 'R4', 'R3', 'R2', 'R1'];

    public function read(array $imagePaths): array
    {
        if ($imagePaths === []) {
            return [];
        }

        $response = (new RosterScreenshotExtractor)->prompt(
            'Extract the alliance roster from these member-list screenshots.',
            attachments: array_map(
                fn (string $path) => Files\Image::fromPath($path),
                array_values($imagePaths),
            ),
            provider: Lab::Anthropic,
            model: config('roster.ocr.model'),
        );

        return $this->normalise($response['members'] ?? []);
    }

    /**
     * Clean names, drop invalid rows, and de-duplicate by name.
     *
     * @param  array<int, array{name?: string, position?: string}>  $rows
     * @return array<int, array{name: string, position: string}>
     */
    private function normalise(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $name = $this->cleanName($row['name'] ?? '');
            $position = $row['position'] ?? '';

            if ($name === '' || ! in_array($position, self::VALID_POSITIONS, true)) {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['name' => $name, 'position' => $position];
        }

        return $result;
    }

    /**
     * Strip non-Latin characters and normalise whitespace in a name.
     */
    private function cleanName(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/u', '', $text) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $ascii));
    }
}
