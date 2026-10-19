<?php

use App\LastWar\Game\Exceptions\CommandFailedException;
use App\LastWar\Game\Exceptions\GameClientException;
use App\LastWar\Game\Exceptions\LoginRejectedException;
use App\LastWar\Game\Exceptions\SessionEndedException;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Sfs\SfsArray;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Sfs\SfsType;
use App\LastWar\Game\Sfs\SfsValue;
use App\LastWar\Game\Transport\GameTransport;
use App\Models\GameAccount;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

beforeEach(function () {
    $this->transport = new FakeGameTransport;
    app()->instance(GameTransport::class, $this->transport);
});

function rejectedLogin(string ...$codes): SfsObject
{
    return (new SfsObject)
        ->putShort('ec', 28)
        ->put('ep', new SfsValue(SfsType::UtfStringArray, $codes));
}

it('logs in with the stored token and waits for init', function () {
    $account = GameAccount::factory()->create(['game_uid' => '1001', 'zone' => 'APS783', 'access_token' => 'stored-token']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->once()->with('APS783')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn ($gate, $deviceId, $zone, $gameUid, $opt = null) => $gameUid === '1001' && $opt === null)
            ->andReturn(GameFixtures::servers());
    });

    $this->transport->script([
        FakeGameTransport::loginReply((new SfsObject)->putUtfString('un', '1001')),
        FakeGameTransport::extension('init', GameFixtures::init('alliance-9')),
    ]);

    $session = app(GameClient::class)->connect($account);

    $login = $this->transport->loginRequest();

    expect($this->transport->connectedTo)->toBe(['game-a.test:10783'])
        ->and($login->getString('zn'))->toBe('APS783')
        ->and($login->getString('un'))->toBe('1001')
        ->and($login->getObject('p')->getString('at'))->toBe('stored-token')
        ->and($login->getObject('p')->getString('serverId'))->toBe('783')
        ->and($session->allianceId())->toBe('alliance-9')
        ->and($account->fresh()->last_login_at)->not->toBeNull();
});

it('refreshes a stale access token before logging in', function () {
    $account = GameAccount::factory()->staleAccessToken()->create(['game_uid' => '1001', 'refresh_token' => 'old-refresh']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => ($args[4] ?? null) === 'refresh' && ($args[6] ?? null) === 'old-refresh')
            ->andReturn(GameFixtures::servers(accessToken: 'new-access', refreshToken: 'new-refresh'));
    });

    $this->transport->script([FakeGameTransport::loginReply(new SfsObject)]);

    app(GameClient::class)->connect($account, waitForInit: false);

    $account->refresh();

    expect($account->access_token)->toBe('new-access')
        ->and($account->refresh_token)->toBe('new-refresh')
        ->and($account->needsTokenRefresh())->toBeFalse()
        ->and($this->transport->loginRequest()->getObject('p')->getString('at'))->toBe('new-access');
});

it('falls back to the login key when the refresh token is rejected', function () {
    $account = GameAccount::factory()->staleAccessToken()->create(['game_uid' => '1001', 'login_key' => 'the-login-key']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => ($args[4] ?? null) === 'refresh')
            ->andReturn(GameFixtures::servers(code: '211'));
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => ($args[4] ?? null) === 'login' && ($args[5] ?? null) === 'the-login-key')
            ->andReturn(GameFixtures::servers(accessToken: 'from-login-key'));
    });

    $this->transport->script([FakeGameTransport::loginReply(new SfsObject)]);

    app(GameClient::class)->connect($account, waitForInit: false);

    expect($account->fresh()->access_token)->toBe('from-login-key');
});

it('follows a serverInfo redirect with the same token', function () {
    $account = GameAccount::factory()->create(['game_uid' => '1001']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->andReturn(GameFixtures::servers());
    });

    $this->transport
        ->script([FakeGameTransport::loginReply((new SfsObject)->putObject('serverInfo', (new SfsObject)
            ->putUtfString('ip', 'shard.test')
            ->putInt('port', 10900)
            ->putUtfString('zone', 'APS900')))])
        ->script([FakeGameTransport::loginReply(new SfsObject)]);

    $session = app(GameClient::class)->connect($account, waitForInit: false);

    expect($this->transport->connectedTo)->toBe(['game-a.test:10783', 'shard.test:10900'])
        ->and($session->zone)->toBe('APS900')
        ->and($account->fresh()->zone)->toBe('APS900');
});

it('dials the next gateway when the first is unreachable', function () {
    $account = GameAccount::factory()->create(['game_uid' => '1001']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->andReturn(GameFixtures::servers());
    });

    $this->transport->unreachableHosts = ['game-a.test'];
    $this->transport->script([FakeGameTransport::loginReply(new SfsObject)]);

    app(GameClient::class)->connect($account, waitForInit: false);

    expect($this->transport->connectedTo)->toBe(['game-b.test:10783']);
});

it('re-issues tokens once when the access token is rejected', function () {
    $account = GameAccount::factory()->create(['game_uid' => '1001', 'refresh_token' => 'refresh']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()->withArgs(fn (...$args) => ($args[4] ?? null) === null)->andReturn(GameFixtures::servers());
        $gsl->shouldReceive('serverList')->once()->withArgs(fn (...$args) => ($args[4] ?? null) === 'refresh')
            ->andReturn(GameFixtures::servers(accessToken: 'rotated'));
    });

    $this->transport
        ->script([FakeGameTransport::loginReply(rejectedLogin('E011'))])
        ->script([FakeGameTransport::loginReply(new SfsObject)]);

    app(GameClient::class)->connect($account, waitForInit: false);

    expect($account->fresh()->access_token)->toBe('rotated');
});

it('records the error when the login is rejected', function () {
    $account = GameAccount::factory()->create(['game_uid' => '1001']);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->andReturn(GameFixtures::servers());
    });

    $this->transport->script([FakeGameTransport::loginReply(rejectedLogin('E005'))]);

    expect(fn () => app(GameClient::class)->connect($account))
        ->toThrow(LoginRejectedException::class, 'E005');

    expect($account->fresh()->last_error)->toContain('E005');
});

it('sends commands and returns their reply', function () {
    $this->transport->script([
        FakeGameTransport::extension('push.chat', new SfsObject),
        FakeGameTransport::extension('al.rank', (new SfsObject)->putArray('list', new SfsArray)),
    ]);
    $this->transport->connect('game.test', 1, 1);

    $reply = app(GameClient::class)->command(
        GameFixtures::session($this->transport),
        'al.rank',
        (new SfsObject)->putUtfString('allianceId', 'abc'),
    );

    $params = $this->transport->sentParams('al.rank');

    expect($reply->has('list'))->toBeTrue()
        ->and($params->getString('allianceId'))->toBe('abc')
        ->and($params->getInt('_id'))->toBe(2);
});

it('raises the command error code', function () {
    $this->transport->script([FakeGameTransport::extension('al.rank', (new SfsObject)->putUtfString('errorCode', 'E100'))]);
    $this->transport->connect('game.test', 1, 1);

    app(GameClient::class)->command(GameFixtures::session($this->transport), 'al.rank');
})->throws(CommandFailedException::class, 'E100');

it('stops when the account is logged in elsewhere', function () {
    $this->transport->script([FakeGameTransport::extension('push.user.off', new SfsObject)]);
    $this->transport->connect('game.test', 1, 1);

    app(GameClient::class)->command(GameFixtures::session($this->transport), 'al.rank');
})->throws(SessionEndedException::class, 'logged in elsewhere');

it('names what arrived when a reply times out', function () {
    $this->transport->script([FakeGameTransport::extension('push.chat', new SfsObject)]);
    $this->transport->connect('game.test', 1, 1);

    GameFixtures::session($this->transport)->connection->waitForCommand(['al.rank'], 0.2);
})->throws(GameClientException::class, 'Received meanwhile: push.chat.');

it('reads the duel activity id from init', function (SfsObject $init, ?string $expected) {
    expect(GameFixtures::session($this->transport, $init)->duelActivityId())->toBe($expected);
})->with([
    'keyed object' => [(new SfsObject)->putObject('activity', (new SfsObject)
        ->putObject('40000', (new SfsObject)->putInt('id', 40000)->putInt('type', 3))
        ->putObject('55000', (new SfsObject)->putInt('id', 55000)->putInt('type', 14)->putUtfString('activityid', '70001'))), '70001'],
    'array' => [(new SfsObject)->putArray('activity', (new SfsArray)
        ->addObject((new SfsObject)->putUtfString('id', '55000')->putUtfString('activityid', '70002'))), '70002'],
    'missing' => [new SfsObject, null],
]);
