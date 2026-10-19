<?php

use App\LastWar\Game\DeviceBinding;
use App\LastWar\Game\Exceptions\CommandFailedException;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Transport\GameTransport;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

beforeEach(function () {
    $this->transport = new FakeGameTransport;
    app()->instance(GameTransport::class, $this->transport);
});

it('binds the account as its own device and stores encrypted credentials', function () {
    $team = Team::factory()->create();

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->once()->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => ($args[4] ?? null) === 'new')
            ->andReturn(GameFixtures::servers(accessToken: 'guest-token', gameUid: '', zone: 'APS12'));
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => $args[3] === '1001' && $args[4] === 'login' && $args[5] === 'login-key')
            ->andReturn(GameFixtures::servers(accessToken: 'own-access', refreshToken: 'own-refresh'));
    });

    $this->transport->script([
        FakeGameTransport::loginReply(new SfsObject),
        FakeGameTransport::extension('init', new SfsObject),
        FakeGameTransport::extension('push.account.send.verify.code', new SfsObject),
        FakeGameTransport::extension('account.login.new', new SfsObject),
        FakeGameTransport::extension('push.account.login.new', (new SfsObject)
            ->putUtfString('loginKey', 'login-key')
            ->putUtfString('gameUid', '1001')),
    ]);

    $binding = app(DeviceBinding::class);
    $pending = $binding->begin('me@example.com');
    $account = $binding->complete($pending, ' 123456 ', $team);

    $guestLogin = $this->transport->loginRequest();
    $finish = $this->transport->sentParams('account.login.new');

    expect($guestLogin->getString('un'))->toBe('')
        ->and($guestLogin->getString('zn'))->toBe('APS12')
        ->and($guestLogin->getObject('p')->getString('at'))->toBe('guest-token')
        ->and($this->transport->sentParams('account.login.send.verify.code')->getString('mail'))->toBe('me@example.com')
        ->and($finish->getString('verifyCode'))->toBe('123456')
        ->and($finish->getString('deviceId'))->toBe($pending->deviceId)
        ->and($account->team_id)->toBe($team->id)
        ->and($account->game_uid)->toBe('1001')
        ->and($account->zone)->toBe('APS783')
        ->and($account->login_key)->toBe('login-key')
        ->and($account->access_token)->toBe('own-access')
        ->and($account->refresh_token)->toBe('own-refresh');

    $raw = DB::table('game_accounts')->first();

    expect($raw->login_key)->not->toBe('login-key')
        ->and($raw->access_token)->not->toBe('own-access')
        ->and($raw->refresh_token)->not->toBe('own-refresh');
});

it('surfaces a rejected verification code', function () {
    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->andReturn(GameFixtures::servers(accessToken: 'guest-token', gameUid: ''));
    });

    $this->transport->script([
        FakeGameTransport::loginReply(new SfsObject),
        FakeGameTransport::extension('init', new SfsObject),
        FakeGameTransport::extension('account.login.send.verify.code', new SfsObject),
        FakeGameTransport::extension('account.login.new', (new SfsObject)->putUtfString('errorCode', 'E200')),
    ]);

    $binding = app(DeviceBinding::class);

    $binding->complete($binding->begin('me@example.com'), '000000', Team::factory()->create());
})->throws(CommandFailedException::class, 'E200');
