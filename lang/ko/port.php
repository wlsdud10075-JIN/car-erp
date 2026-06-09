<?php

// i18n — 항구 마스터 (admin/ports). 공통은 common.php.
return [
    'title' => '항구 마스터',
    'subtitle' => 'CIPL 드롭다운 옵션 — 총 :count건',
    'create_btn' => '항구 추가',
    'search_ph' => '이름 · 코드',
    'all_types' => '전체 구분',
    'empty' => '항구가 없습니다.',
    'deactivate' => '비활성화',
    'activate' => '활성화',
    'edit_title' => '항구 수정',
    'create_title' => '항구 추가',

    'col' => [
        'type' => '구분',
        'name' => '항구명',
        'code' => '코드',
    ],
    'field' => [
        'type' => '구분',
        'name' => '항구명',
        'name_ph' => '예: BUSAN, KOREA / 평택항',
        'code' => '코드',
        'code_ph' => '예: 020-77-002',
        'code_note' => '부두 코드. 괄호 안 숫자 — 없는 항구는 비워둠',
        'active_note' => '활성 (드롭다운에 노출)',
    ],

    'type' => [
        'loading' => 'Port of Loading (출발항)',
        'unloading' => '반입지 (한국 부두)',
        'discharge' => 'Discharge Port (목적항)',
    ],

    'dup' => '같은 구분에 동일 이름 항구가 이미 있습니다.',
    'saved' => '항구 정보가 저장됐습니다.',
    'activated' => '활성화됨',
    'deactivated' => '비활성화됨',
];
