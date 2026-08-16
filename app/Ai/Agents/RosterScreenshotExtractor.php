<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads an alliance roster from in-game "MEMBER LIST" screenshots via a vision model.
 *
 * The reader decides how many screenshots to send per request (all at once for cloud
 * models, one at a time for local Ollama models). The provider and model come from
 * config (see config/roster.php), so the same agent works on a paid model (Claude) or
 * a free one (Ollama, Gemini free tier). No sampling attributes (Temperature/TopP) are
 * set — Claude rejects them from Opus 4.7 onwards.
 *
 * MaxTokens is generous because Claude counts thinking tokens against it, and thinking
 * is on by default on Opus 5; a tight budget truncates the roster mid-list.
 */
#[MaxTokens(32000)]
#[Timeout(600)]
class RosterScreenshotExtractor implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        You extract an alliance roster from screenshots of a mobile war game's "MEMBER LIST" screen.

        The attached images are sequential, overlapping scroll captures of ONE list, in order.
        Each row shows a member's name (coloured text), their power, level, and a Manage button.

        Positions:
        - R5 is the single leader, shown in the badge near the top of every screenshot.
        - R4, R3, R2, R1 appear as section header bars within the list. Every member row
          belongs to the most recent section header above it.

        Rules:
        - Return every distinct member exactly once. Because the screenshots overlap, the same
          member appears in several of them — de-duplicate by name.
        - IGNORE the row of "featured" icons near the top (titles like Warlord, Recruiter, Mvse,
          Butler). Those names also appear in the list below; only list each member once, from the list.
        - Transcribe each name exactly as shown but EXCLUDE non-Latin characters (e.g. Chinese).
          If a name is only non-Latin characters, return whatever Latin letters or digits remain.
        - Order the result from R5 down through R4, R3, R2, to R1.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'members' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'name' => $schema->string()->required(),
                    'position' => $schema->string()->enum(['R5', 'R4', 'R3', 'R2', 'R1'])->required(),
                ]))
                ->required(),
        ];
    }
}
