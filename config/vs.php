<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VS Score Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The vision model used to read VS "Weekly Rank" screenshots.
    |
    | This mirrors config/roster.php but is configured separately so score
    | reading can run on a different model than roster reading — score digits are
    | far less forgiving than positions, so this side may warrant the stronger
    | model even when the roster does not.
    |
    | provider: a Laravel\Ai\Enums\Lab value, e.g. "anthropic" or "gemini". Each
    |           provider reads its own key: ANTHROPIC_API_KEY from the Claude
    |           Console (https://console.anthropic.com), GEMINI_API_KEY from
    |           Google AI Studio (https://aistudio.google.com/apikey).
    |
    | model:    a vision-capable model belonging to that provider. Provider and
    |           model must be changed together — "gemini" paired with a Claude
    |           model is a 404 from the API, not a config error caught here.
    |
    | Run `php artisan vs:ocr-check --provider=… --model=…` to measure a
    | candidate against the fixtures before changing these defaults.
    |
    | Gemini Flash is the default because it is free and, measured on the
    | tests/stubs/vs fixtures, read all 83 point totals correctly — the failure
    | that matters here. It scored 97.6% against Claude Sonnet's identical 97.6%
    | and Opus's 100%; the gap is two stylised names ("sc0rpxiii" for
    | "scorpxiii"), which RosterNameMatcher recovers well above its threshold.
    |
    */

    'ocr' => [
        'provider' => env('VS_OCR_PROVIDER', 'gemini'),
        'model' => env('VS_OCR_MODEL', 'gemini-flash-latest'),
    ],

];
