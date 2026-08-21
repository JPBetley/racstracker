<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->racsTeam = Team::factory()->create(['id' => 1, 'name' => 'RACS', 'slug' => 'racs']);
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    expect($user->belongsToTeam($this->racsTeam))->toBeTrue()
        ->and($user->teamRole($this->racsTeam))->toBe(TeamRole::Member)
        ->and($user->current_team_id)->toBe($this->racsTeam->id)
        ->and($user->personalTeam())->toBeNull();
});

test('registering emails a verification link and leaves the account unverified', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a freshly registered user is sent to verify before reaching the app', function () {
    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->get(route('dashboard', absolute: false))
        ->assertRedirect(route('verification.notice'));
});
