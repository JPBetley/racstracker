<?php

namespace App\Imports\Ocr\Contracts;

interface VsScoreScreenshotReader
{
    /**
     * Read a weekly VS leaderboard from a set of "Weekly Rank" ranking screenshots.
     *
     * The screenshots are overlapping scroll captures of a single ranked list. The
     * reader extracts each commander's rank, name and weekly point total, merges the
     * captures, and returns one de-duplicated leaderboard ordered from the highest
     * points down.
     *
     * @param  array<int, string>  $imagePaths  Absolute paths to the screenshots, in capture order.
     * @return array<int, array{rank: int, name: string, points: int}>
     */
    public function read(array $imagePaths): array;
}
