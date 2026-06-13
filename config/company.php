<?php

/*
|--------------------------------------------------------------------------
| 회사(SSANCAR) 고정 정보
|--------------------------------------------------------------------------
|
| 서류 자동 생성(11단계) — Invoice / Sales Contract / 양도증명서 등에서
| 공통으로 참조하는 SSANCAR LTD. 정보. 향후 변경 시 이 한 곳만 수정.
|
| 서명/도장은 현재 텍스트 placeholder. PNG로 교체 시 'seller_signature_path'를
| storage/app/public/signatures/...png 등으로 변경 → Blade에서
| <img src="{{ public_path(...) }}"> 로 출력.
|
*/

return [
    // 서류 템플릿 세트 폴더 (resources/templates/{set}/). 회사별 회사정보 인쇄본 분기.
    // default='system'(ssancar). karaba 등 → .env COMPANY_TEMPLATE_SET=karaba.
    'template_set' => env('COMPANY_TEMPLATE_SET', 'system'),

    'name_ko' => '주식회사 싼카',
    'name_en' => 'SSANCAR CO., LTD.',
    'address_ko' => '경기도 시흥시 산기대학로 163, A동 328호 (정왕동)',
    'address_en' => '163 Sangideahak-ro, Siheung-si, Gyeonggi-do, Korea',
    'tel' => '031-499-1988',
    'fax' => '031-499-1989',
    'email' => 'man99777@naver.com',
    'business_number' => '662-81-00898',
    'representative_ko' => '조태신',
    'representative_en' => 'Cho Tae Shin',

    // 자동차매매업자 정보 (양도증명서 등)
    'dealer_registration_number' => '02-4115-000476',

    // 은행 (Invoice용)
    'bank' => [
        'beneficiary_name' => 'SSANCAR CO., LTD.',
        'bank_name' => 'SHINHAN BANK',
        'swift_code' => 'KOEXKRSE',
        'bank_address' => '20, Sejong-Daero 9-Gil, Jung-Gu, Seoul, Korea',
        'account_number' => '430-910063-81104',
    ],

    // 서명/도장 — 텍스트 placeholder. PNG 도입 시 path로 교체 (null이면 텍스트)
    'seller_signature_path' => null,         // 예: storage_path('app/public/signatures/seller.png')
    'seller_signature_text' => '(Signature)',
    'seller_stamp_path' => null,             // 예: storage_path('app/public/signatures/stamp.png')
    'seller_stamp_text' => '[직인]',
];
