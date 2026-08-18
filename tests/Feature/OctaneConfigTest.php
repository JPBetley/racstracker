<?php

use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Listeners\CreateConfigurationSandbox;
use Laravel\Octane\Listeners\CreateUrlGeneratorSandbox;
use Laravel\Octane\Listeners\FlushAuthenticationState;
use Laravel\Octane\Listeners\FlushSessionState;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\PrepareLivewireForNextOperation;
use Laravel\Octane\Octane;

it('serves with frankenphp unless the environment says otherwise', function () {
    expect(config('octane.server'))->toBe(env('OCTANE_SERVER', 'frankenphp'));
});

/**
 * Octane reuses one booted application across requests, so anything the app writes
 * to a container singleton outlives the request that wrote it. These listeners are
 * what keeps that from happening, and dropping one is silent until two tenants
 * share a worker, so each is pinned here rather than left to the published stub.
 */
it('isolates per-request state between requests handled by the same worker', function (string $listener) {
    expect(config('octane.listeners.'.RequestReceived::class))->toContain($listener);
})->with([
    // Team-scoped routes rely on URL::defaults('current_team'), set per request by
    // SetTeamUrlDefaults. Without the sandbox the url generator is shared, and one
    // tenant's slug is baked into every link the next request renders.
    'url generator' => CreateUrlGeneratorSandbox::class,
    'configuration' => CreateConfigurationSandbox::class,
    'authentication' => FlushAuthenticationState::class,
    'session' => FlushSessionState::class,
    // Every page in this app is Livewire; its manager caches resolved components.
    'livewire' => PrepareLivewireForNextOperation::class,
]);

/**
 * Screenshot uploads are copied onto the configured disk during the request and read
 * back from there by the queued OCR job, so the PHP temp file has no reader once the
 * response is sent. A long-lived worker never runs PHP's own shutdown cleanup, so
 * without this listener those temp files accumulate for the life of the worker.
 */
it('deletes uploaded temp files once the request is finished', function () {
    expect(config('octane.listeners.'.RequestTerminated::class))
        ->toContain(FlushUploadedFiles::class);
});

it('keeps the framework defaults Octane ships with', function () {
    expect(config('octane.listeners.'.RequestReceived::class))
        ->toEqualCanonicalizing([
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ]);
});
