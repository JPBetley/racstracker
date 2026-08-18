<?php

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('the user model requires email verification', function () {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

test('an unverified user is held at the verification notice', function (string $route) {
    $user = User::factory()->unverified()->create();

    URL::defaults(['current_team' => $user->currentTeam->slug]);

    $this->actingAs($user)
        ->get(route($route))
        ->assertRedirect(route('verification.notice'));
})->with([
    'dashboard',
    'members.index',
    'conductors.index',
    'conductors.plan',
    'scores.index',
    'scores.import',
    'team-settings.edit',
    'appearance.edit',
    'security.edit',
    'teams.index',
]);

test('an unverified user cannot accept a team invitation', function () {
    $user = User::factory()->unverified()->create();

    $invitation = TeamInvitation::factory()->create(['email' => $user->email]);

    $this->actingAs($user)
        ->get(route('invitations.accept', $invitation))
        ->assertRedirect(route('verification.notice'));

    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('an unverified user can still reach their profile to fix a mistyped email', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

test('an unverified user can correct their email and be sent a fresh link', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'corrected@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->call('resendVerificationNotification');

    expect($user->fresh()->email)->toBe('corrected@example.com');

    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('a verified user passes the verification gate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
        ->assertOk();
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();
    $team = $user->personalTeam();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $response->assertRedirect("/{$team->slug}/dashboard?verified=1");
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $team = $user->personalTeam();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect("/{$team->slug}/dashboard?verified=1");

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});
