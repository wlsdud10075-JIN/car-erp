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

        // NICE 직접 호출 (게이트웨이 이식) — ssancarerp(heymancar.com 박스)에서만 사용.
        // NICE IP 화이트리스트가 이 박스(54.116.7.83)라 같은 박스의 car-erp 가 직접 2단계 호출 가능.
        // 다른 박스(heymanerp 등)는 이 박스 /provide/ 를 경유(IP 불일치로 직접 불가). 미설정 시 NiceDirectClient 호출 실패.
        'direct' => [
            'api_url' => env('NICE_DIRECT_API_URL', 'https://niceab.nicednr.co.kr/carInfos'),
            'api_key' => env('NICE_DIRECT_API_KEY', ''),
            'login_id' => env('NICE_DIRECT_LOGIN_ID', ''),
            'business_number' => (int) env('NICE_DIRECT_BUSINESS_NUMBER', 0),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 연동 B 수신 — board(매입보드)와 공유하는 HMAC 비밀키.
    // 미설정 시 수신 엔드포인트는 모든 요청을 401 로 거부(안전밸브).
    // 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
    'purchase_sync' => [
        'hmac_secret' => env('CAR_ERP_HMAC_SECRET'),
    ],

    // board 영업 포털 읽기 API — 쓰기(purchase_sync)와 분리된 별도 시크릿.
    // 미설정 시 읽기 엔드포인트 전부 401(안전밸브). 권위 스펙 = docs/integration/board-portal-api.md.
    'board_read' => [
        'hmac_secret' => env('CAR_ERP_READ_HMAC_SECRET'),
    ],

];
