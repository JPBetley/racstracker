<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roster Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The AI provider and model used to read alliance roster screenshots. Any
    | vision-capable provider supported by the Laravel AI SDK works here. The
    | default is Anthropic's Claude, which needs an ANTHROPIC_API_KEY from the
    | Claude Console (https://console.anthropic.com). Set these in your .env to
    | switch to a cheaper Claude model, or to a free option (a local Ollama
    | model, or Google Gemini's free tier) without touching code.
    |
    | provider: one of the Laravel\Ai\Enums\Lab values, e.g. "anthropic",
    |           "ollama", "gemini", "openai", "openrouter".
    | model:    a vision-capable model string for that provider.
    |
    */

    'ocr' => [
        'provider' => env('ROSTER_OCR_PROVIDER', 'anthropic'),
        'model' => env('ROSTER_OCR_MODEL', 'claude-opus-5'),
    ],

];
