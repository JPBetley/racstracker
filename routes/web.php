<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('members', 'pages::members.index')->name('members.index');

        Route::livewire('members/import', 'pages::members.import')->name('members.import');

        Route::livewire('scores', 'pages::scores.index')->name('scores.index');

        Route::livewire('scores/import', 'pages::scores.import')->name('scores.import');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
