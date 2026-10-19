<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Sfs\SfsObject;
use App\Models\GameAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Reads the Alliance Duel (VS) member rankings straight from the game.
 */
class VsRankingReader
{
    /**
     * `al.battle.rank.info` type for the whole week's ranking (0 is a single day).
     */
    private const int WEEKLY = 1;

    /**
     * The game server's clock runs this many hours behind UTC, so a VS day and week
     * roll over at 02:00 UTC rather than midnight.
     */
    private const int SERVER_UTC_OFFSET_HOURS = -2;

    public function __construct(private readonly GameClient $client) {}

    /**
     * Fetch every alliance member's VS points for the current week.
     *
     * The ranking lists both alliances in the matchup, so only entries for the given
     * alliance are kept. Logging in kicks the account off any other device.
     *
     * @return list<array{uid: string, name: string, points: int}>
     *
     * @throws GameClientException
     */
    public function weeklyScores(GameAccount $account, string $allianceId): array
    {
        $session = $this->client->connect($account);

        try {
            $reply = $this->client->command($session, 'al.battle.rank.info', (new SfsObject)
                ->putLong('type', self::WEEKLY));
        } finally {
            $session->close();
        }

        return collect($reply->getArray('rankInfo')?->objects() ?? [])
            ->filter(fn (SfsObject $entry): bool => $entry->getString('aid') === $allianceId && $entry->getString('uid') !== '')
            ->map(fn (SfsObject $entry): array => [
                'uid' => $entry->getString('uid'),
                'name' => trim($entry->getString('name')),
                'points' => $entry->getInt('score'),
            ])
            ->values()
            ->all();
    }

    /**
     * The Monday that starts the VS week in progress at the given moment, by server time.
     */
    public static function weekStartAt(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)
            ->utc()
            ->addHours(self::SERVER_UTC_OFFSET_HOURS)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->startOfDay();
    }
}
