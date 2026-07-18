<?php

// i18n — 영업담당자 관리 (erp/salesmen/index). 공통은 common.php.
return [
    'title' => '영업담당자 관리',
    'total' => '총 :count명',
    'create_btn' => '담당자 등록',
    'search_ph' => '이름 · 이메일 · 전화',
    'empty' => '영업담당자가 없습니다.',
    'cashflow' => '캐시플로우',
    'cashflow_view' => '캐시플로우 보기',
    'carryover_badge' => '미청산 이월',
    'delete_confirm' => ':name 담당자를 삭제하시겠습니까?',
    'saved' => '영업담당자 정보가 저장됐습니다.',
    'deleted' => '영업담당자가 삭제됐습니다.',
    'edit_title' => '영업담당자 수정',
    'create_title' => '영업담당자 등록',

    'col' => [
        'name' => '이름',
        'account' => '연결 계정',
    ],
    'field' => [
        'name' => '이름',
        'name_ph' => '김영업',
        'initials' => '이니셜',
        'initials_ph' => '예: JK',
        'initials_note' => 'Proforma Invoice 번호 접두에 사용 — {이니셜}MU{차대번호 숫자}',
        'account' => '연결 계정',
        'account_none' => '-- 연결 안 함 --',
        'linked_none' => '연결 안 됨',
        'settlement_type' => '정산 분류',
        'type_unset' => '미설정 — 사용자 관리에서 입력 필요',
        'type_no_account' => '로그인 계정 미연결',
    ],

    'master_banner' => '이름·이메일·정산 분류는 :link에서 변경. 이 화면은 보충 정보(전화·메모·활성) 입력 전용.',
    'type_note' => '정산 분류는 :link에서 변경 (role=영업)',
    'users_link' => '사용자 관리',

    'type' => [
        'employee' => '사내직원',
        'freelance' => '프리랜서',
    ],
    'type_suffix' => [
        'employee' => '(건당 정산)',
        'freelance' => '(비율 정산)',
    ],
];
