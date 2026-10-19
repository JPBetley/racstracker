<?php

use App\Console\Commands\GameCommand;
use App\LastWar\Game\DeviceBinding;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Gsl\GateHandshake;
use App\LastWar\Game\PendingBinding;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;
use App\Models\GameAccount;
use App\Models\Team;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

beforeEach(function () {
    $this->transport = new FakeGameTransport;
    $this->account = GameAccount::factory()->create();
    $this->team = $this->account->team;
});

it('binds a team through the emailed code', function () {
    $pending = new PendingBinding(GameFixtures::session($this->transport), new GateHandshake('https://gate.test', ''), 'device_n3d', 'me@example.com');
    $team = Team::factory()->create();

    $this->mock(DeviceBinding::class, function (MockInterface $binding) use ($pending, $team) {
        $binding->shouldReceive('begin')->once()->with('me@example.com')->andReturn($pending);
        $binding->shouldReceive('complete')->once()
            ->withArgs(fn ($given, $code, $forTeam) => $given === $pending && $code === '123456' && $forTeam->is($team))
            ->andReturn(GameAccount::factory()->make(['game_uid' => '1001', 'zone' => 'APS783']));
    });

    $this->artisan('lastwar:game-bind', ['team' => $team->slug, '--email' => 'me@example.com'])
        ->expectsConfirmation('Continue?', 'yes')
        ->expectsQuestion('Verification code', '123456')
        ->expectsOutputToContain('Bound game account 1001 on APS783')
        ->assertSuccessful();
});

it('requires a bound account', function () {
    $this->artisan('lastwar:game-login', ['team' => Team::factory()->create()->slug])
        ->expectsOutputToContain('has no bound game account')
        ->assertFailed();
});

it('summarizes a login', function () {
    $this->mock(GameClient::class, fn (MockInterface $client) => $client->shouldReceive('connect')->once()
        ->andReturn(GameFixtures::session($this->transport, GameFixtures::init('alliance-9'))));

    $this->artisan('lastwar:game-login', ['team' => $this->team->slug])
        ->expectsOutputToContain('alliance-9')
        ->assertSuccessful();
});

it('reports a rejected login', function () {
    $this->mock(GameClient::class, fn (MockInterface $client) => $client->shouldReceive('connect')
        ->andThrow(new LoginRejectedException('The game server rejected the login (ec=28, E005).', ['E005'])));

    $this->artisan('lastwar:game-login', ['team' => $this->team->slug])
        ->expectsOutputToContain('E005')
        ->assertFailed();
});

it('sends a probe command with typed parameters and prints the reply', function () {
    $this->mock(GameClient::class, function (MockInterface $client) {
        $client->shouldReceive('connect')->once()->andReturn(GameFixtures::session($this->transport));
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($session, $command, SfsObject $params) => $command === 'al.rank'
                && $params->getString('allianceId') === 'abc'
                && $params->value('page')->type->name === 'Int')
            ->andReturn((new SfsObject)->putUtfString('member', 'Commander'));
    });

    $this->artisan('lastwar:game-command', [
        'team' => $this->team->slug,
        'cmd' => 'al.rank',
        '--param' => ['allianceId=abc', 'page:int=2'],
    ])->expectsOutputToContain('"member": "Commander"')->assertSuccessful();
});

it('refuses commands that do not look read-only without --force', function () {
    $this->mock(GameClient::class, fn (MockInterface $client) => $client->shouldNotReceive('connect'));

    $this->artisan('lastwar:game-command', ['team' => $this->team->slug, 'cmd' => 'alliance.donate'])
        ->expectsOutputToContain('does not look read-only')
        ->assertFailed();
});

it('parses typed parameters', function () {
    $params = GameCommand::parseParams(['activityId=70000', 'n:long=5', 'flag:bool=true', 'ratio:double=0.5', 'eq=a=b']);

    expect($params->value('activityId')->type->name)->toBe('UtfString')
        ->and($params->toArray())->toBe(['activityId' => '70000', 'n' => 5, 'flag' => true, 'ratio' => 0.5, 'eq' => 'a=b']);
});

it('summarizes the current duel day', function () {
    $now = now()->getTimestampMs();
    $alliance = fn (string $abbr, int $score) => (new SfsObject)
        ->putUtfString('abbr', $abbr)
        ->putUtfString('alName', "{$abbr} Alliance")
        ->putInt('serverId', 783)
        ->putLong('alScore', $score)
        ->putInt('win', 1)
        ->putArray('scoreHistory', (new SfsArray)->addObject((new SfsObject)->putInt('day', 1)->putLong('score', 5_000_000)));

    $reply = (new SfsObject)
        ->putInt('ranking', 0)
        ->putArray('eventList', (new SfsArray)->addObject((new SfsObject)
            ->putInt('t', 15)
            ->putLong('st', $now - 1000)
            ->putLong('et', $now + 1000)
            ->putInt('eventId', 110003)
            ->putObject('userScore', (new SfsObject)->putLong('score', 1_234_567))
            ->putArray('vsAllianceInfo', (new SfsArray)
                ->addObject($alliance('RACS', 98_000_000))
                ->addObject($alliance('FOE', 87_000_000)))));

    $this->mock(GameClient::class, function (MockInterface $client) use ($reply) {
        $init = (new SfsObject)->putObject('activity', (new SfsObject)
            ->putObject('55000', (new SfsObject)->putInt('id', 55000)->putUtfString('activityid', '70009')));

        $client->shouldReceive('connect')->andReturn(GameFixtures::session($this->transport, $init));
        $client->shouldReceive('command')->once()
            ->withArgs(fn ($session, $command, SfsObject $params) => $command === 'hero.event.info.get'
                && $params->value('activityId')->type->name === 'UtfString'
                && $params->getString('activityId') === '70009')
            ->andReturn($reply);
    });

    $this->artisan('lastwar:game-duel', ['team' => $this->team->slug])
        ->expectsOutputToContain('your points today: 1,234,567')
        ->expectsOutputToContain('98,000,000')
        ->assertSuccessful();
});
