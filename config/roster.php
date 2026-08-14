<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roster Screenshot OCR
    |--------------------------------------------------------------------------
    |
    | The AI provider and model used to read alliance roster screenshots. Any
    | vision-capable provider supported by the Laravel AI SDK works here. Set
    | these in your .env to switch between a paid, high-accuracy model (e.g.
    | Anthropic's claude-opus-4-8) and a free option (a local Ollama model, or
    | Google Gemini's free tier) without touching code.
    |
    | provider: one of the Laravel\Ai\Enums\Lab values, e.g. "ollama",
    |           "gemini", "anthropic", "openai", "openrouter".
    | model:    a vision-capable model string for that provider.
    |
    */

    'ocr' => [
        'provider' => env('ROSTER_OCR_PROVIDER', 'ollama'),
        'model' => env('ROSTER_OCR_MODEL', 'qwen2.5vl:3b'),
    ],

];
