<?php

namespace App\Imports\Ocr;

use App\Ai\Agents\RosterScreenshotExtractor;
use App\Imports\Ocr\Contracts\RosterScreenshotReader;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files;

/**
 * Reads alliance roster screenshots with a vision model via the Laravel AI SDK.
 *
 * The provider and model come from config/roster.php, so this works with a paid,
 * high-accuracy model (Claude) or a free one (a local Ollama model, or Gemini's free
 * tier) by changing .env only.
 *
 * Cloud models read all screenshots in one prompt and de-duplicate the overlapping
 * scroll captures themselves. Small local (Ollama) models lose recall when handed
 * many images at once, so each screenshot is read in its own request and the rows
 * are merged here. Names are cleaned and de-duplicated again as defensive insurance.
 */
class AiVisionRosterScreenshotReader implements RosterScreenshotReader
{
    private const VALID_POSITIONS = ['R5', 'R4', 'R3', 'R2', 'R1'];

    public function read(array $imagePaths): array
    {
        if ($imagePaths === []) {
            return [];
        }

        $provider = Lab::from(config('roster.ocr.provider'));
        $model = config('roster.ocr.model');
        $paths = array_values($imagePaths);

        // Local models read one screenshot at a time; cloud models take them all at once.
        $batches = $provider === Lab::Ollama
            ? array_map(fn (string $path): array => [$path], $paths)
            : [$paths];

        $rows = [];

        foreach ($batches as $batch) {
            $response = (new RosterScreenshotExtractor)->prompt(
                'Extract the alliance roster from these member-list screenshots.',
                attachments: array_map(fn (string $path) => Files\Image::fromPath($path), $batch),
                provider: $provider,
                model: $model,
            );

            foreach ($response['members'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $this->normalise($rows);
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
