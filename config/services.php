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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // NICE 차량정보 — ssancar-erp 의 /provide/api/nice-lookup/ 미들웨어를 경유한다.
    // NICE 직접 호출·키·IP 화이트리스트는 ssancar-erp 가 책임. CAR-ERP 는 이 엔드포인트에 POST 만.
    'nice' => [
        'provide_url' => env('NICE_PROVIDE_URL', ''),     // /provide/api/nice-lookup/ 까지 포함한 전체 URL
        'provide_token' => env('NICE_PROVIDE_TOKEN', ''),  // X-SSANCAR-API-KEY 헤더 토큰
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
