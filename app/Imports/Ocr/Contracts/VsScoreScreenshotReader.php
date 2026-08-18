<?php

namespace App\Imports\Ocr\Contracts;

interface VsScoreScreenshotReader
{
    /**
     * Read a VS leaderboard from a set of ranking screenshots.
     *
     * The screenshots are overlapping scroll captures of a single ranked list. The
     * reader extracts each commander's rank, name and point total, merges the
     * captures, and returns one de-duplicated leaderboard ordered from the highest
     * points down.
     *
     * Which ranking tab was captured is the uploader's responsibility — the app only
     * ever stores one score per member per week, so the reader has no notion of it.
     *
     * The screenshots are read from a filesystem disk rather than a local path, because
     * the upload and the OCR run in different processes — and, once deployed, on
     * different machines with no filesystem between them.
     *
     * @param  array<int, string>  $imagePaths  Disk-relative paths to the screenshots, in capture order.
     * @param  string|null  $disk  The disk holding them, or null for the default disk.
     * @return array<int, array{rank: int, name: string, points: int}>
     */
    public function read(array $imagePaths, ?string $disk = null): array;
}
