<?php

namespace App\Imports\Ocr\Contracts;

interface RosterScreenshotReader
{
    /**
     * Read alliance roster members from a set of member-list screenshots.
     *
     * The screenshots are overlapping scroll captures of a single list. The
     * reader extracts each member's name and position, merges the captures,
     * and returns one ordered, de-duplicated roster.
     *
     * @param  array<int, string>  $imagePaths  Absolute paths to the screenshots, in capture order.
     * @return array<int, array{name: string, position: string}>
     */
    public function read(array $imagePaths): array;
}
