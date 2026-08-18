<?php

use App\Models\User;

test('guests see the promo page with a way to sign in', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Command post for the RACS alliance.')
        ->assertSee('Server 1919 · Last War: Survival', escape: false)
        ->assertSee(route('login'), escape: false)
        ->assertSee(route('register'), escape: false);
});

test('the promo page no longer ships the default laravel content', function () {
    $this->get(route('home'))
        ->assertDontSee('Laravel has an incredibly rich ecosystem')
        ->assertDontSee('laracasts.com')
        ->assertDontSee('Deploy now');
});

test('members are pointed at their dashboard instead of the login links', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('dashboard'), escape: false)
        ->assertDontSee(route('login'), escape: false);
});

test('a member without a current team is not shown a broken dashboard link', function () {
    $user = User::factory()->create();
    $user->forceFill(['current_team_id' => null])->save();

    $this->actingAs($user->fresh())
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Open dashboard');
});
