<?php

use App\Models\User;

test('guests are redirected from the docs page', function () {
    User::factory()->create();

    $this->get(route('docs'))->assertRedirect(route('login'));
});

test('docs page loads for an authenticated team member', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs'))
        ->assertOk();
});
