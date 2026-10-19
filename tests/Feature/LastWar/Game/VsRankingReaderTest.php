<?php

use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\VsRankingReader;
use App\Models\GameAccount;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

it('reads the weekly ranking and keeps only the given alliance', function () {
    $entry = fn (string $uid, string $aid, int $score) => (new SfsObject)
        ->putUtfString('uid', $uid)
        ->putUtfString('name', " Player {$uid} ")
        ->putLong('score', $score)
        ->putUtfString('aid', $aid);

    $reply = (new SfsObject)->putLong('type', 1)->putArray('rankInfo', (new SfsArray)
        ->addObject($entry('1001', 'ours', 83_504_436))
        ->addObject($entry('2001', 'theirs', 90_000_000))
        ->addObject($entry('1002', 'ours', 9_403_126)));

    $session = GameFixtures::session(new FakeGameTransport);

    $this->mock(GameClient::class, function (MockInterface $client) use ($session, $reply) {
        $client->shouldReceive('connect')->once()->andReturn($session);
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($given, $command, SfsObject $params) => $given === $session
                && $command === 'al.battle.rank.info'
                && $params->value('type')->type->name === 'Long'
                && $params->getInt('type') === 1
                && ! $params->has('day'))
            ->andReturn($reply);
    });

    expect(app(VsRankingReader::class)->weeklyScores(GameAccount::factory()->create(), 'ours'))->toBe([
        ['uid' => '1001', 'name' => 'Player 1001', 'points' => 83_504_436],
        ['uid' => '1002', 'name' => 'Player 1002', 'points' => 9_403_126],
    ]);
});

it('throws when the ranking lists other alliances but not the given one', function () {
    $entry = fn (string $uid, string $aid) => (new SfsObject)
        ->putUtfString('uid', $uid)
        ->putUtfString('name', "Player {$uid}")
        ->putLong('score', 1_000)
        ->putUtfString('aid', $aid);

    $reply = (new SfsObject)->putArray('rankInfo', (new SfsArray)
        ->addObject($entry('1001', 'ours'))
        ->addObject($entry('2001', 'theirs')));

    $this->mock(GameClient::class, function (MockInterface $client) use ($reply) {
        $client->shouldReceive('connect')->andReturn(GameFixtures::session(new FakeGameTransport));
        $client->shouldReceive('command')->andReturn($reply);
    });

    expect(fn () => app(VsRankingReader::class)->weeklyScores(GameAccount::factory()->create(), 'misconfigured'))
        ->toThrow(GameClientException::class, 'lists no members of alliance [misconfigured]; it lists alliances [ours, theirs]');
});

it('returns no scores when the ranking is empty', function () {
    $this->mock(GameClient::class, function (MockInterface $client) {
        $client->shouldReceive('connect')->andReturn(GameFixtures::session(new FakeGameTransport));
        $client->shouldReceive('command')->andReturn(new SfsObject);
    });

    expect(app(VsRankingReader::class)->weeklyScores(GameAccount::factory()->create(), 'ours'))->toBe([]);
});
