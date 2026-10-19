<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Sfs\SfsObject;
use App\Models\GameAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Reads an alliance's member list and Alliance Duel (VS) rankings straight from the game.
 *
 * The reads take an open session so one login can serve several of them; logging in
 * kicks the account off any other device, so callers should log in as rarely as they can.
 */
class AllianceReader
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
     * Log in once and read both the member list and this week's VS scores.
     *
     * @return array{
     *     members: list<array{uid: string, name: string, rank: int, power: int}>,
     *     scores: list<array{uid: string, name: string, points: int}>,
     * }
     *
     * @throws GameClientException
     */
    public function read(GameAccount $account, string $allianceId): array
    {
        $session = $this->client->connect($account);

        try {
            return [
                'members' => $this->members($session, $allianceId),
                'scores' => $this->weeklyScores($session, $allianceId),
            ];
        } finally {
            $session->close();
        }
    }

    /**
     * Log in and read only the alliance's member list.
     *
     * @return list<array{uid: string, name: string, rank: int, power: int}>
     *
     * @throws GameClientException
     */
    public function membersOnly(GameAccount $account, string $allianceId): array
    {
        $session = $this->client->connect($account);

        try {
            return $this->members($session, $allianceId);
        } finally {
            $session->close();
        }
    }

    /**
     * Fetch every member of the alliance from the game's alliance Members screen.
     *
     * The reply is checked against the alliance asked for, so a wrong id fails rather
     * than syncing some other alliance's roster.
     *
     * @return list<array{uid: string, name: string, rank: int, power: int}>
     *
     * @throws GameClientException
     */
    public function members(GameSession $session, string $allianceId): array
    {
        $reply = $this->client->command($session, 'al.rank', (new SfsObject)
            ->putUtfString('allianceId', $allianceId));

        if ($reply->getString('allianceId') !== $allianceId) {
            throw new GameClientException(sprintf(
                'The alliance member list is for alliance [%s], not [%s].',
                $reply->getString('allianceId'),
                $allianceId,
            ));
        }

        $list = $reply->getArray('list');

        if ($list === null) {
            throw new GameClientException("The alliance member list for [{$allianceId}] has no members list.");
        }

        return collect($list->objects())
            ->filter(fn (SfsObject $entry): bool => $entry->getString('uid') !== '')
            ->map(fn (SfsObject $entry): array => [
                'uid' => $entry->getString('uid'),
                'name' => trim($entry->getString('name')),
                'rank' => $entry->getInt('rank'),
                'power' => $entry->getInt('power'),
            ])
            ->values()
            ->all();
    }

    /**
     * Fetch every alliance member's VS points for the current week.
     *
     * The ranking lists both alliances in the matchup, so only entries for the given
     * alliance are kept. A ranking that lists other alliances but not this one means
     * the configured alliance id is wrong, so that throws rather than reporting an
     * empty week.
     *
     * @return list<array{uid: string, name: string, points: int}>
     *
     * @throws GameClientException
     */
    public function weeklyScores(GameSession $session, string $allianceId): array
    {
        $reply = $this->client->command($session, 'al.battle.rank.info', (new SfsObject)
            ->putLong('type', self::WEEKLY));

        $entries = collect($reply->getArray('rankInfo')?->objects() ?? []);
        $ours = $entries->filter(fn (SfsObject $entry): bool => $entry->getString('aid') === $allianceId && $entry->getString('uid') !== '');

        if ($ours->isEmpty() && $entries->isNotEmpty()) {
            throw new GameClientException(sprintf(
                'The VS ranking lists no members of alliance [%s]; it lists alliances [%s].',
                $allianceId,
                $entries->map(fn (SfsObject $entry): string => $entry->getString('aid'))->unique()->implode(', '),
            ));
        }

        return $ours
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
