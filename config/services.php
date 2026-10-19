<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | The Last War API toolkit (https://api.lastwar.tools), which exposes live
    | game data: alliance rosters and VS scores.
    |
    | key:          sent as the "X-API-Key" header on every request.
    | session_key:  optional, sent as a "session_key" query parameter. Requests
    |               carrying one run immediately against your own game account;
    |               requests without one are queued behind a shared connection
    |               pool and may take an unbounded amount of time. Every /vs/*
    |               endpoint requires it. Obtain one by running the Capture Tool
    |               (https://github.com/LastWarTools/Capture-Tool) and uploading
    |               the result via POST /auth/credentials/upload.
    | alliance_id:  fallback alliance to import when a team has none of its own,
    |               as a 32-character hex ID. Found via
    |               GET /rankings/{server_id}/alliances.
    */
    'lastwar' => [
        'base_url' => env('LASTWAR_BASE_URL', 'https://api.lastwar.tools'),
        'key' => env('LASTWAR_API_KEY'),
        'session_key' => env('LASTWAR_SESSION_KEY'),
        'alliance_id' => env('LASTWAR_ALLIANCE_ID'),
        'timeout' => env('LASTWAR_TIMEOUT', 30),

        /*
        | The native game client (app/LastWar/Game), which logs into the game
        | servers directly as its own bound device instead of going through
        | api.lastwar.tools. Access tokens are bound to the client build that
        | issued them, so bump app_version/version_code when the game forces an
        | update (check-version returns updateType=2).
        |
        | account_import: a lastwar:game-account-export string, read by
        | lastwar:game-account-import when no --data is given. Set it only for
        | the import, then remove it: it grants access to the game account.
        */
        'game' => [
            'gate_hosts' => [
                'https://lastwar-serverlist-cf.lastwarapp.net',
                'https://lastwar-serverlist-us-aws-ali.lastwargame.com',
                'https://lastwar-serverlist-us-gcp-ali.lastwargame.com',
            ],
            'package_name' => 'com.fun.lastwar.gp',
            'app_version' => env('LASTWAR_GAME_APP_VERSION', '1.0.364'),
            'version_code' => env('LASTWAR_GAME_VERSION_CODE', '1892'),
            'unity_version' => '440',
            'timeout' => env('LASTWAR_GAME_TIMEOUT', 15),
            'account_import' => env('LASTWAR_GAME_ACCOUNT'),
        ],
    ],

];
