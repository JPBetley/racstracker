<?php

use App\LastWar\Game\GameAccountTransfer;
use App\LastWar\Game\GameClient;
use App\LastWar\Game\Gsl\GslClient;
use App\LastWar\Game\Sfs\SfsObject;
use App\LastWar\Game\Transport\GameTransport;
use App\Models\GameAccount;
use App\Models\Team;
use Mockery\MockInterface;
use Tests\Support\FakeGameTransport;
use Tests\Support\GameFixtures;

beforeEach(function () {
    $this->source = GameAccount::factory()->create([
        'device_id' => 'device_n3d',
        'game_uid' => '1726986595001904',
        'zone' => 'APS1919',
        'login_key' => 'long-lived-key',
    ]);
    $this->target = Team::factory()->create(['slug' => 'racs-prod']);
});

it('carries the device identity and login key but not the tokens', function () {
    $transfer = app(GameAccountTransfer::class);
    $exported = $transfer->export($this->source);

    $imported = $transfer->import($this->target, $exported);

    expect($exported)->toStartWith('lwga1.')
        ->and($exported)->not->toContain('long-lived-key')
        ->and($imported->team_id)->toBe($this->target->id)
        ->and($imported->only('device_id', 'game_uid', 'zone', 'login_key', 'app_version', 'version_code'))
        ->toBe($this->source->only('device_id', 'game_uid', 'zone', 'login_key', 'app_version', 'version_code'))
        ->and($imported->access_token)->toBeNull()
        ->and($imported->refresh_token)->toBeNull()
        ->and($imported->needsTokenRefresh())->toBeTrue();
});

it('rejects strings that are not a valid export', function (string $data, string $message) {
    expect(fn () => app(GameAccountTransfer::class)->import($this->target, $data))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'wrong prefix' => ['hello', 'not a game account export'],
    'corrupt' => ['lwga1.@@@', 'corrupt'],
    'missing fields' => ['lwga1.'.rtrim(strtr(base64_encode('{"device_id":"d"}'), '+/', '-_'), '='), 'missing: game_uid, login_key'],
]);

it('exports from the command line', function () {
    $this->artisan('lastwar:game-account-export', ['team' => $this->source->team->slug])
        ->expectsOutputToContain('Treat it like a password')
        ->expectsOutputToContain('lwga1.')
        ->assertSuccessful();
});

it('imports from --data without prompting', function () {
    $exported = app(GameAccountTransfer::class)->export($this->source);

    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod', '--data' => $exported])
        ->expectsOutputToContain('Bound game account 1726986595001904 on APS1919 to team [racs-prod]')
        ->assertSuccessful();

    expect($this->target->fresh()->gameAccount->login_key)->toBe('long-lived-key');
});

it('imports from LASTWAR_GAME_ACCOUNT when no --data is given', function () {
    config()->set('services.lastwar.game.account_import', app(GameAccountTransfer::class)->export($this->source));

    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod'])->assertSuccessful();

    expect($this->target->fresh()->gameAccount)->not->toBeNull();
});

it('refuses to replace an existing account without --force', function () {
    GameAccount::factory()->for($this->target)->create(['game_uid' => 'existing']);
    $exported = app(GameAccountTransfer::class)->export($this->source);

    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod', '--data' => $exported])
        ->expectsOutputToContain('already has a game account')
        ->assertFailed();

    expect($this->target->fresh()->gameAccount->game_uid)->toBe('existing');

    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod', '--data' => $exported, '--force' => true])
        ->assertSuccessful();

    expect($this->target->fresh()->gameAccount->game_uid)->toBe('1726986595001904')
        ->and(GameAccount::where('team_id', $this->target->id)->count())->toBe(1);
});

it('reports a missing export and bad data', function () {
    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod'])
        ->expectsOutputToContain('Pass the export with --data=')
        ->assertFailed();

    $this->artisan('lastwar:game-account-import', ['team' => 'racs-prod', '--data' => 'nope'])
        ->expectsOutputToContain('not a game account export')
        ->assertFailed();
});

it('trades the login key for fresh tokens on the first login after import', function () {
    $imported = app(GameAccountTransfer::class)->import($this->target, app(GameAccountTransfer::class)->export($this->source));
    app()->instance(GameTransport::class, $transport = new FakeGameTransport);

    $this->mock(GslClient::class, function (MockInterface $gsl) {
        $gsl->shouldReceive('checkVersion')->andReturn(GameFixtures::gate());
        $gsl->shouldReceive('serverList')->once()
            ->withArgs(fn (...$args) => $args[1] === 'device_n3d' && ($args[4] ?? null) === 'login' && ($args[5] ?? null) === 'long-lived-key')
            ->andReturn(GameFixtures::servers(accessToken: 'prod-access', refreshToken: 'prod-refresh', gameUid: '1726986595001904', zone: 'APS1919'));
    });

    $transport->script([FakeGameTransport::loginReply(new SfsObject)]);

    app(GameClient::class)->connect($imported, waitForInit: false);

    expect($imported->fresh()->access_token)->toBe('prod-access')
        ->and($imported->fresh()->refresh_token)->toBe('prod-refresh')
        ->and($transport->loginRequest()->getObject('p')->getString('at'))->toBe('prod-access');
});
