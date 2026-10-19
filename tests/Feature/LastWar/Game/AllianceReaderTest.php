<?php

use App\LastWar\Game\AllianceReader;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\GameSession;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;
use App\Models\GameAccount;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

/**
 * An `al.rank` reply for the given alliance, as the game's Members screen receives it.
 */
function memberListReply(string $allianceId, array $members): SfsObject
{
    $list = new SfsArray;

    foreach ($members as [$uid, $name, $rank, $power]) {
        $list->addObject((new SfsObject)
            ->putUtfString('uid', $uid)
            ->putUtfString('name', $name)
            ->putInt('rank', $rank)
            ->putLong('power', $power)
            ->putInt('mainCityLv', 30)
            ->putBool('online', false));
    }

    return (new SfsObject)
        ->putUtfString('allianceId', $allianceId)
        ->putArray('list', $list)
        ->putArray('allianceOfficialArr', new SfsArray);
}

/**
 * A weekly `al.battle.rank.info` entry.
 */
function rankEntry(string $uid, string $aid, int $score): SfsObject
{
    return (new SfsObject)
        ->putUtfString('uid', $uid)
        ->putUtfString('name', " Player {$uid} ")
        ->putLong('score', $score)
        ->putUtfString('aid', $aid);
}

it('reads the alliance member list', function () {
    $session = GameFixtures::session(new FakeGameTransport);

    $this->mock(GameClient::class, function (MockInterface $client) use ($session) {
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($given, $command, SfsObject $params) => $given === $session
                && $command === 'al.rank'
                && $params->value('allianceId')->type->name === 'UtfString'
                && $params->getString('allianceId') === 'ours')
            ->andReturn(memberListReply('ours', [
                ['1001', ' Leader ', 5, 210_000_000],
                ['1002', 'Officer', 4, 150_000_000],
                ['1003', 'Grunt', 3, 90_000_000],
            ]));
    });

    expect(app(AllianceReader::class)->members($session, 'ours'))->toBe([
        ['uid' => '1001', 'name' => 'Leader', 'rank' => 5, 'power' => 210_000_000],
        ['uid' => '1002', 'name' => 'Officer', 'rank' => 4, 'power' => 150_000_000],
        ['uid' => '1003', 'name' => 'Grunt', 'rank' => 3, 'power' => 90_000_000],
    ]);
});

it('throws when the member list is for another alliance', function () {
    $this->mock(GameClient::class, function (MockInterface $client) {
        $client->shouldReceive('command')->andReturn(memberListReply('theirs', [['2001', 'Stranger', 3, 1]]));
    });

    expect(fn () => app(AllianceReader::class)->members(GameFixtures::session(new FakeGameTransport), 'ours'))
        ->toThrow(GameClientException::class, 'is for alliance [theirs], not [ours]');
});

it('throws when the member list reply has no list', function () {
    $this->mock(GameClient::class, function (MockInterface $client) {
        $client->shouldReceive('command')->andReturn((new SfsObject)->putUtfString('allianceId', 'ours'));
    });

    expect(fn () => app(AllianceReader::class)->members(GameFixtures::session(new FakeGameTransport), 'ours'))
        ->toThrow(GameClientException::class, 'has no members list');
});

it('reads the members and the weekly scores in one login', function () {
    $session = GameFixtures::session(new FakeGameTransport);
    $account = GameAccount::factory()->create();

    $this->mock(GameClient::class, function (MockInterface $client) use ($session, $account) {
        $client->shouldReceive('connect')->once()
            ->withArgs(fn (GameAccount $given) => $given->is($account))
            ->andReturn($session);
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($given, $command) => $given === $session && $command === 'al.rank')
            ->andReturn(memberListReply('ours', [['1001', 'Leader', 5, 210_000_000]]));
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($given, $command) => $given === $session && $command === 'al.battle.rank.info')
            ->andReturn((new SfsObject)->putArray('rankInfo', (new SfsArray)->addObject(rankEntry('1001', 'ours', 500))));
    });

    expect(app(AllianceReader::class)->read($account, 'ours'))->toBe([
        'members' => [['uid' => '1001', 'name' => 'Leader', 'rank' => 5, 'power' => 210_000_000]],
        'scores' => [['uid' => '1001', 'name' => 'Player 1001', 'points' => 500]],
    ]);
});

it('reads only the members in a login of its own', function () {
    $session = GameFixtures::session(new FakeGameTransport);

    $this->mock(GameClient::class, function (MockInterface $client) use ($session) {
        $client->shouldReceive('connect')->once()->andReturn($session);
        $client->shouldReceive('command')->once()
            ->withArgs(fn (GameSession $given, string $command) => $command === 'al.rank')
            ->andReturn(memberListReply('ours', [['1001', 'Leader', 5, 1]]));
    });

    expect(app(AllianceReader::class)->membersOnly(GameAccount::factory()->create(), 'ours'))
        ->toBe([['uid' => '1001', 'name' => 'Leader', 'rank' => 5, 'power' => 1]]);
});

it('reads the weekly ranking and keeps only the given alliance', function () {
    $reply = (new SfsObject)->putLong('type', 1)->putArray('rankInfo', (new SfsArray)
        ->addObject(rankEntry('1001', 'ours', 83_504_436))
        ->addObject(rankEntry('2001', 'theirs', 90_000_000))
        ->addObject(rankEntry('1002', 'ours', 9_403_126)));

    $session = GameFixtures::session(new FakeGameTransport);

    $this->mock(GameClient::class, function (MockInterface $client) use ($session, $reply) {
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($given, $command, SfsObject $params) => $given === $session
                && $command === 'al.battle.rank.info'
                && $params->value('type')->type->name === 'Long'
                && $params->getInt('type') === 1
                && ! $params->has('day'))
            ->andReturn($reply);
    });

    expect(app(AllianceReader::class)->weeklyScores($session, 'ours'))->toBe([
        ['uid' => '1001', 'name' => 'Player 1001', 'points' => 83_504_436],
        ['uid' => '1002', 'name' => 'Player 1002', 'points' => 9_403_126],
    ]);
});

it('throws when the ranking lists other alliances but not the given one', function () {
    $reply = (new SfsObject)->putArray('rankInfo', (new SfsArray)
        ->addObject(rankEntry('1001', 'ours', 1_000))
        ->addObject(rankEntry('2001', 'theirs', 1_000)));

    $this->mock(GameClient::class, function (MockInterface $client) use ($reply) {
        $client->shouldReceive('command')->andReturn($reply);
    });

    expect(fn () => app(AllianceReader::class)->weeklyScores(GameFixtures::session(new FakeGameTransport), 'misconfigured'))
        ->toThrow(GameClientException::class, 'lists no members of alliance [misconfigured]; it lists alliances [ours, theirs]');
});

it('returns no scores when the ranking is empty', function () {
    $this->mock(GameClient::class, function (MockInterface $client) {
        $client->shouldReceive('command')->andReturn(new SfsObject);
    });

    expect(app(AllianceReader::class)->weeklyScores(GameFixtures::session(new FakeGameTransport), 'ours'))->toBe([]);
});
