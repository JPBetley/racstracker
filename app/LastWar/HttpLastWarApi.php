<?php

namespace App\LastWar;

use App\LastWar\Contracts\LastWarApi;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpLastWarApi implements LastWarApi
{
    /**
     * Fetch the current members of an alliance.
     *
     * @return array<int, array{uid: string, name: string, rank: int, power: int}>
     *
     * @throws LastWarApiException
     */
    public function allianceMembers(string $allianceId): array
    {
        $response = $this->request()->get("/alliance/{$allianceId}/members", array_filter([
            'sort_by' => 'rank',
            'session_key' => config('services.lastwar.session_key'),
        ]));

        $this->guard($response, "Fetching members for alliance [{$allianceId}]");

        return collect($response->json('members') ?? [])
            ->filter(fn ($member): bool => is_array($member) && filled($member['uid'] ?? null))
            ->map(fn (array $member): array => [
                'uid' => (string) $member['uid'],
                'name' => trim((string) ($member['name'] ?? '')),
                'rank' => (int) ($member['rank'] ?? 0),
                'power' => (int) ($member['power'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Build a request carrying the API key.
     *
     * Configuration is read per call so the key can be swapped at runtime, and
     * retries cover the transient failures the shared connection pool produces.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.lastwar.base_url'))
            ->withHeaders(['X-API-Key' => config('services.lastwar.key')])
            ->timeout((int) config('services.lastwar.timeout'))
            ->retry(2, 1000, throw: false);
    }

    /**
     * Fail loudly on a non-2xx response so the import records the error.
     *
     * @throws LastWarApiException
     */
    private function guard(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw LastWarApiException::fromResponse($response, $context);
        }
    }
}
