<?php

namespace App\Actions\Members;

use App\Enums\MemberPosition;
use App\Imports\Ocr\RosterNameMatcher;
use App\Models\Member;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SyncAllianceRoster
{
    public function __construct(private RosterNameMatcher $matcher) {}

    /**
     * Reconcile a team's roster against the alliance membership reported by the API.
     *
     * Members present in the API but missing here are created, members that have
     * left are deactivated, and members who rejoin are reactivated. Nothing is
     * ever deleted, so historical scores stay attached to the member who earned
     * them whether or not they are still in the alliance.
     *
     * @param  array<int, array{uid: string, name: string, rank: int, power: int}>  $apiMembers
     * @return array{added: int, reactivated: int, updated: int, deactivated: int, unchanged: int}
     *
     * @throws InvalidArgumentException|ValidationException
     */
    public function handle(Team $team, array $apiMembers): array
    {
        $apiMembers = $this->deduplicate($apiMembers);

        if ($apiMembers === []) {
            throw new InvalidArgumentException(
                'The alliance returned no members; refusing to empty the roster.',
            );
        }

        $this->guardPositionCaps($apiMembers);

        return DB::transaction(function () use ($team, $apiMembers) {
            $existing = $team->roster()->with('aliases')->get();
            $byUid = $existing->whereNotNull('uid')->keyBy('uid');

            /** @var Collection<int, Member> $unlinked */
            $unlinked = $existing->whereNull('uid')->values();

            $counts = ['added' => 0, 'reactivated' => 0, 'updated' => 0, 'deactivated' => 0, 'unchanged' => 0];
            $keptIds = [];

            foreach ($apiMembers as $row) {
                $member = $byUid->get($row['uid']) ?? $this->matchByName($row['name'], $unlinked);

                if ($member === null) {
                    $created = $team->roster()->create([
                        'uid' => $row['uid'],
                        'name' => $row['name'],
                        'position' => MemberPosition::fromApiRank($row['rank']),
                        'is_active' => true,
                    ]);

                    $created->recordAlias($row['name']);

                    $keptIds[] = $created->id;
                    $counts['added']++;

                    continue;
                }

                $unlinked = $unlinked->reject(fn (Member $candidate): bool => $candidate->is($member))->values();
                $keptIds[] = $member->id;

                $wasInactive = ! $member->is_active;
                $previousName = $member->name;

                $member->fill([
                    'uid' => $row['uid'],
                    'name' => $row['name'],
                    'position' => MemberPosition::fromApiRank($row['rank']),
                    'is_active' => true,
                ]);

                $changed = $member->isDirty();

                if ($wasInactive) {
                    $counts['reactivated']++;
                } elseif ($changed) {
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }

                $member->save();

                // The previous name covers members that predate aliases, whose
                // stored name would otherwise never be recorded.
                $member->recordAlias($previousName);
                $member->recordAlias($row['name']);
            }

            $counts['deactivated'] = $team->roster()
                ->active()
                ->whereNotIn('id', $keptIds)
                ->update(['is_active' => false]);

            return $counts;
        });
    }

    /**
     * Find an existing member for an API name among those not yet linked to a UID.
     *
     * Only runs on the first sync, when stored members were created from OCR and
     * carry no UID. Name matching is fuzzy because OCR mangles stylised names;
     * once a UID is stored, matching is exact forever after.
     *
     * @param  Collection<int, Member>  $candidates
     */
    private function matchByName(string $name, Collection $candidates): ?Member
    {
        return $candidates->isEmpty() ? null : $this->matcher->suggest($name, $candidates);
    }

    /**
     * Collapse duplicate UIDs, keeping the last occurrence.
     *
     * @param  array<int, array{uid: string, name: string, rank: int, power: int}>  $apiMembers
     * @return array<int, array{uid: string, name: string, rank: int, power: int}>
     */
    private function deduplicate(array $apiMembers): array
    {
        return collect($apiMembers)
            ->filter(fn (array $row): bool => filled($row['uid']) && filled($row['name']))
            ->keyBy('uid')
            ->values()
            ->all();
    }

    /**
     * Reject a roster that would breach a position cap.
     *
     * The API's rank numbering is mapped onto R1-R5 positions, so an unexpected
     * change on their side would silently reassign everyone. Capped positions
     * (one R5, ten R4) are the invariant that catches it, and failing here leaves
     * the stored roster untouched.
     *
     * @param  array<int, array{uid: string, name: string, rank: int, power: int}>  $apiMembers
     *
     * @throws ValidationException
     */
    private function guardPositionCaps(array $apiMembers): void
    {
        $counts = collect($apiMembers)
            ->countBy(fn (array $row): string => MemberPosition::fromApiRank($row['rank'])->value);

        foreach ($counts as $position => $count) {
            $cap = MemberPosition::from($position)->maxPerTeam();

            if ($cap !== null && $count > $cap) {
                throw ValidationException::withMessages([
                    'position' => __('The alliance reported :count :position members but only :cap are allowed.', [
                        'count' => $count,
                        'position' => $position,
                        'cap' => $cap,
                    ]),
                ]);
            }
        }
    }
}
