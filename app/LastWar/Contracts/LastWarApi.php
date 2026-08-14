<?php

namespace App\LastWar\Contracts;

use App\LastWar\LastWarApiException;

interface LastWarApi
{
    /**
     * Fetch the current members of an alliance.
     *
     * Only the fields this application tracks are returned; see
     * `.ai/skills/last-war-api/references/endpoints.md` for the full response.
     *
     * @param  string  $allianceId  The alliance's 32-character hex ID.
     * @return array<int, array{uid: string, name: string, rank: int, power: int}>
     *
     * @throws LastWarApiException
     */
    public function allianceMembers(string $allianceId): array;
}
