<?php

namespace App\Jobs\Imports;

use App\Actions\Scores\SaveScore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class ImportVsScores extends ImportStep
{
    /**
     * Record a weekly VS score for each reviewed row.
     *
     * Rows that were never matched to a roster member, that point at a member outside
     * this team, or that repeat a member already handled in this run are skipped and
     * reported rather than failing the import. An existing score for the week is
     * overwritten, because a screenshot is a better source than whatever was typed in
     * by hand; members absent from the screenshots are left untouched.
     */
    protected function run(): void
    {
        $target = $this->import->payload['week_start'] ?? null;

        if ($target === null) {
            throw new RuntimeException("Import [{$this->import->id}] has no target week.");
        }

        $weekStart = CarbonImmutable::parse($target)->startOfWeek(CarbonInterface::MONDAY);
        $saveScore = app(SaveScore::class);
        $roster = $this->import->team->roster()->get()->keyBy('id');
        $rows = $this->import->payload['scores'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = [];
        $handled = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $points = $row['points'] ?? null;
            $memberId = $row['member_id'] ?? null;

            if (! is_numeric($points)) {
                $skipped[] = ['name' => $name, 'reason' => __('Missing points.')];

                continue;
            }

            if ($memberId === null) {
                $skipped[] = ['name' => $name, 'reason' => __('No matching roster member.')];

                continue;
            }

            $member = $roster->get((int) $memberId);

            if ($member === null) {
                $skipped[] = ['name' => $name, 'reason' => __('Not on this roster.')];

                continue;
            }

            if (isset($handled[$member->id])) {
                $skipped[] = ['name' => $name, 'reason' => __('Duplicate row for this member.')];

                continue;
            }

            $existed = $member->scores()->whereDate('week_start', $weekStart)->exists();

            $saveScore->handle($member, $weekStart, (int) $points);

            $handled[$member->id] = true;
            $existed ? $updated++ : $created++;
        }

        $this->record([
            'week_start' => $weekStart->toDateString(),
            'total' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }
}
