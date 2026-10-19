<?php

namespace App\LastWar\Game;

use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;

/**
 * A logged-in game session.
 */
final readonly class GameSession
{
    public function __construct(
        public GameConnection $connection,
        public string $zone,
        public string $gameUid,
        public SfsObject $loginReply,
        public ?SfsObject $init,
    ) {}

    /**
     * The player's alliance id as the `init` push reports it.
     */
    public function allianceId(): ?string
    {
        $id = $this->init?->getObject('alliance')?->getString('uid')
            ?: $this->init?->getObject('user')?->getString('allianceId');

        return filled($id) ? $id : null;
    }

    /**
     * The id `hero.event.info.get` needs for the Alliance Duel.
     *
     * The duel is activity 55000 (type 14) in the `init` activity map, which may be
     * an object keyed by id or an array. Its `activityid` (e.g. "70000"), not its
     * own id, is what the detail read must send.
     */
    public function duelActivityId(): ?string
    {
        $activities = $this->init?->get('activity');
        $entries = match (true) {
            $activities instanceof SfsObject => array_filter(array_map($activities->getObject(...), $activities->keys())),
            $activities instanceof SfsArray => $activities->objects(),
            default => [],
        };

        foreach ($entries as $entry) {
            if (($entry->getString('id') === '55000' || $entry->getInt('type') === 14) && filled($entry->getString('activityid'))) {
                return $entry->getString('activityid');
            }
        }

        return null;
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
