<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 운영 관리자 계정 (ProductionSeeder)
    |--------------------------------------------------------------------------
    |
    | ProductionSeeder 가 생성하는 관리자 2개의 자격증명. 평문 'password' 대신
    | .env 환경변수에서 읽는다. config() 로 노출해 config:cache 환경에서도
    | 안전하게 읽힌다 (시더에서 env() 직접 호출 시 캐시되면 null 반환 → 계정 누락).
    |
    | email + password 둘 중 하나라도 비어 있으면 해당 계정 생성을 건너뛰고
    | 경고를 출력한다. .env 변경 후에는 `php artisan config:clear`(또는 재캐시)
    | 후 `db:seed` 해야 반영된다.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', '시스템관리자'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    'boss' => [
        'name' => env('BOSS_NAME', '최고관리자'),
        'email' => env('BOSS_EMAIL'),
        'password' => env('BOSS_PASSWORD'),
    ],

];
