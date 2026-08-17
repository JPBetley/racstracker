<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roster Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The Claude model used to read alliance roster screenshots. The pipeline
    | runs on Anthropic and needs an ANTHROPIC_API_KEY from the Claude Console
    | (https://console.anthropic.com).
    |
    | model: a vision-capable Claude model. Set ROSTER_OCR_MODEL in your .env to
    |        move to a cheaper read, e.g. "claude-sonnet-5".
    |
    */

    'ocr' => [
        'model' => env('ROSTER_OCR_MODEL', 'claude-sonnet-5'),
    ],

];
