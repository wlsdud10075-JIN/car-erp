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
        'per_unit_tier' => '차등 정산(tier) 적용',
        'per_unit_tier_hint' => '켜면 총마진 100만원 이상 20만원 · 매입합계 1억원 이상 총마진의 25%가 적용됩니다. 끄면 건당 10만원 고정(손해 차량은 0원). 승계받은 바이어 건은 켜짐 여부와 무관하게 건당 5만원입니다.',
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
    // 퇴사 승계 (jin 2026-08-27) — A 가 하던 일을 B 가 통째로 받는다.
    'handover' => [
        'button' => '승계',
        'title' => ':name 의 담당을 넘깁니다',
        'rule' => '정산에 아직 안 빠진 진행중 차량과 바이어가 넘어갑니다. 정산으로 빠진 차량은 그대로 두어 원 담당자가 정산받습니다.',
        'to' => '받을 사람',
        'reason' => '사유 (선택)',
        'reason_ph' => '2026-08 퇴사',
        'moving' => '넘어가는 것',
        'skipping' => '그대로 두는 것',
        'buyers' => '바이어 :n명',
        'vehicles' => '진행중 차량 :n대',
        'skipped' => '정산으로 빠진 차량 :n대 — 원 담당자가 그대로 정산받습니다',
        'rewrites' => '⚠ :n명은 이미 다른 승계 이력이 있습니다. 원 담당자 기록이 이번 것으로 바뀝니다.',
        'mark_on' => '받는 분이 사내직원이라 「승계받은 바이어」 표시가 켜집니다 — 이후 이 바이어 건은 건당 5만원으로 정산됩니다. 원 담당자의 기존 정산은 바뀌지 않습니다.',
        'mark_off' => '받는 분이 프리랜서라 「승계받은 바이어」 표시는 켜지 않습니다 — 비율 정산이라 승계 여부가 금액에 영향을 주지 않습니다.',
        'run' => '승계 실행',
        'done' => '승계 완료 — 바이어 :buyers명 · 차량 :vehicles대 이관 (정산분 :skipped대 제외)',
        'forbidden' => '퇴사 승계는 [관리] 이상만 실행할 수 있습니다.',
    ],
];
