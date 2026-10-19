<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithGameAccount;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Sfs\SfsObject;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lastwar:game-duel {team : The team slug} {--activity= : Override the duel activity id (read from init otherwise)} {--raw : Dump the whole reply}')]
#[Description('Fetch the current Alliance Duel (VS) state as the bound game account')]
class GameDuel extends Command
{
    use InteractsWithGameAccount;

    /**
     * The duel's eventList entries carry this type.
     */
    private const int DUEL_EVENT_TYPE = 15;

    /**
     * The duel's activity id when init does not list it (confirmed live on 2026-10-06).
     */
    private const string FALLBACK_ACTIVITY_ID = '70000';

    /**
     * Execute the console command.
     */
    public function handle(GameClient $client): int
    {
        $account = $this->resolveGameAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        try {
            $session = $client->connect($account);
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($session->init === null) {
            $this->warn('No init push arrived; the server may ignore commands on this session.');
        }

        $activityId = $this->option('activity') ?: $session->duelActivityId() ?? self::FALLBACK_ACTIVITY_ID;
        $this->line("Requesting duel activity {$activityId}.");

        try {
            $reply = $client->command($session, 'hero.event.info.get', (new SfsObject)
                ->putUtfString('activityId', (string) $activityId));
        } catch (GameClientException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $session->close();
        }

        if ($this->option('raw')) {
            $this->printJson($reply->toArray());

            return self::SUCCESS;
        }

        $day = $this->currentDay($reply);

        if ($day === null) {
            $this->warn('No duel day is running right now (Sunday is a rest day).');
            $this->line('Reply keys: '.implode(', ', $reply->keys()));

            return self::SUCCESS;
        }

        $this->info(sprintf('Duel theme %s, your points today: %s', $day->getString('eventId'), number_format($day->getObject('userScore')?->getInt('score') ?? 0)));

        $this->table(
            ['Alliance', 'Server', 'Duel score', 'Week wins', 'Daily history'],
            collect($day->getArray('vsAllianceInfo')?->objects() ?? [])->map(fn (SfsObject $alliance): array => [
                sprintf('[%s] %s', $alliance->getString('abbr'), $alliance->getString('alName')),
                $alliance->getString('serverId'),
                number_format($alliance->getInt('alScore')),
                $alliance->getString('win'),
                collect($alliance->getArray('scoreHistory')?->objects() ?? [])
                    ->map(fn (SfsObject $entry): string => "d{$entry->getString('day')}: ".number_format($entry->getInt('score')))
                    ->implode(', '),
            ])->all(),
        );

        $this->line('Top-level <comment>ranking</comment> field (unexplored, may hold per-member data):');
        $this->printJson(['ranking' => $reply->value('ranking')?->toPhp()]);

        return self::SUCCESS;
    }

    /**
     * Find the duel eventList entry whose window contains now.
     */
    private function currentDay(SfsObject $reply): ?SfsObject
    {
        $now = now()->getTimestampMs();

        return collect($reply->getArray('eventList')?->objects() ?? [])->first(function (SfsObject $entry) use ($now): bool {
            $start = $entry->has('st') ? $entry->getInt('st') : $entry->getInt('begintime');
            $end = $entry->has('et') ? $entry->getInt('et') : $entry->getInt('endtime');

            return $entry->getInt('t') === self::DUEL_EVENT_TYPE && $now >= $start && $now < $end;
        });
    }
}
