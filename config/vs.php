<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VS Score Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The Claude model used to read VS "Weekly Rank" screenshots. The pipeline
    | runs on Anthropic and needs an ANTHROPIC_API_KEY from the Claude Console
    | (https://console.anthropic.com).
    |
    | This mirrors config/roster.php but is configured separately so score
    | reading can run on a different model than roster reading — score digits are
    | far less forgiving than positions, so this side may warrant the stronger
    | model even when the roster does not.
    |
    | model: a vision-capable Claude model. Set VS_OCR_MODEL in your .env to move
    |        to a cheaper read, e.g. "claude-sonnet-5".
    |
    */

    'ocr' => [
        'model' => env('VS_OCR_MODEL', 'claude-opus-5'),
    ],

];
