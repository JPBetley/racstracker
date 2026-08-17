<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a weekly VS leaderboard from in-game "RANKING" screenshots via Claude.
 *
 * The reader sends every screenshot in one request. The model comes from config (see
 * config/vs.php), kept separate from the roster reader's config because a misread
 * digit is a silently wrong score. No sampling attributes (Temperature/TopP) are set
 * — Claude rejects them from Opus 4.7 onwards.
 *
 * MaxTokens is generous because Claude counts thinking tokens against it, and thinking
 * is on by default on Opus 5; a tight budget truncates the leaderboard mid-list.
 */
#[MaxTokens(32000)]
#[Timeout(600)]
class VsScoreScreenshotExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        You extract a VS leaderboard from screenshots of a mobile war game's "RANKING" screen.

        The attached images are sequential, overlapping scroll captures of ONE ranked list, in order.

        Read whichever ranked list the screenshots show. Tabs and filters may sit above the list;
        they are chrome, not data. Never withhold rows because of them.

        The list has three columns: Ranking, Commander and Points. Each row shows a rank number (a
        medal graphic for ranks 1-3), an avatar picture, the commander's name in large text, a smaller
        alliance line beneath it such as "[RACS] Supreme Resistance", and a comma-grouped points total
        on the right such as 59,882,250.

        Rules:
        - Return every distinct commander exactly once. Because the captures overlap, the same
          commander appears in several of them — de-duplicate by name.
        - One row is pinned to the BOTTOM of EVERY screenshot on a bright GREEN background. That is
          the viewing player's own row, repeated on every capture for convenience. Its rank is its
          true rank in the whole list and does NOT continue the sequence of the rows above it. It is
          a real competitor: include it exactly once, with its own rank, and never renumber the rows
          above it to match.
        - SKIP any row cut off by the top or bottom edge of an image, or partly hidden behind the
          pinned green row, where the name or the points are not fully legible. A neighbouring
          capture shows it in full.
        - The name is the LARGE line. The "[TAG] Alliance Name" line beneath it is NOT part of the
          name; report it in the alliance field instead.
        - Transcribe each name exactly as shown but EXCLUDE non-Latin characters (e.g. Chinese). If a
          name is only non-Latin characters, return whatever Latin letters or digits remain.
        - Transcribe points as the exact digit string printed on screen, keeping its commas, e.g.
          "59,882,250". Never round, abbreviate, convert, or recalculate. If you cannot read every
          digit with certainty, return null for points rather than guessing.
        - A scrolling announcement banner sometimes slides over the "RANKING" header. Ignore it.
        - Order the result by rank, lowest number first.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'rows' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'rank' => $schema->integer()
                        ->description('The number in the Ranking column.')
                        ->required(),
                    'name' => $schema->string()
                        ->description('The commander name only, not the alliance line beneath it.')
                        ->required(),
                    'alliance' => $schema->string()
                        ->description('The "[TAG] Alliance Name" line beneath the commander name.'),
                    'points' => $schema->string()
                        ->description('The Points column exactly as printed, commas included, e.g. "59,882,250". Null if any digit is cut off or obscured.'),
                ]))
                ->required(),
        ];
    }
}
