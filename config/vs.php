<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VS Score Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The AI provider and model used to read VS "Weekly Rank" screenshots. This
    | mirrors config/roster.php but is configured separately so score reading can
    | run on a different model than roster reading — score digits are far less
    | forgiving than positions, so this side may warrant a stronger model.
    |
    | provider: one of the Laravel\Ai\Enums\Lab values, e.g. "anthropic",
    |           "ollama", "gemini", "openai", "openrouter".
    | model:    a vision-capable model string for that provider.
    |
    */

    'ocr' => [
        'provider' => env('VS_OCR_PROVIDER', 'anthropic'),
        'model' => env('VS_OCR_MODEL', 'claude-opus-5'),
    ],

];
