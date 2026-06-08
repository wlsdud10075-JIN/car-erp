<?php

// i18n — 채권관리 (erp/receivables). progress=domain.progress, role=domain.role 재사용.
return [
    'title' => '채권관리',
    'subtitle' => '미수금 현황 · 회수 이력 · 위험도 모니터링',
    'admin_only' => '권한: 관리자 전용',
    'forbidden' => '채권관리에 접근할 권한이 없습니다.',

    'tab' => [
        'all' => '전체',
        'before_shipping' => '선적전 미수',
        'after_shipping' => '선적후 미수',
        'deposit' => '디파짓 (적립금 사용)',
    ],

    'kpi' => [
        'total_sale' => '총 매출 (KRW 환산)',
        'total_paid' => '총 입금',
        'total_unpaid' => '총 미수금',
        'risk_count' => '위험 건수 (위험 + 심각)',
    ],
    'unit_won' => '원',
    'unit_count' => '대',

    'search_ph' => '차량번호·브랜드 검색',
    'all_salesman' => '담당자 전체',
    'all_buyer' => '바이어 전체',
    'all_progress' => '진행상태 전체',
    'all_risk' => '위험도 전체',
    'ratio_all' => '미납률 ALL',
    'ratio_min' => ':percent%↑',

    'risk' => [
        'safe' => '안전',
        'caution' => '주의',
        'danger' => '위험',
        'critical' => '심각',
    ],

    'col' => [
        'no' => '번호',
        'vehicle_no' => '차량번호',
        'salesman' => '담당자',
        'buyer' => '바이어',
        'sale_total' => '판매합계',
        'unpaid' => '미납금',
        'unpaid_ratio' => '미납률',
        'progress' => '진행상태',
        'bl' => 'BL',
        'risk' => '위험도',
        'manager' => '채권담당',
    ],
    'empty' => '조회된 차량이 없습니다.',
    'buyer_none' => '바이어 미지정',
    'mobile_unpaid' => '미납',

    // 슬라이드 패널 (회수 이력)
    'history' => '회수 이력',
    'manager_section' => '채권담당자',
    'manager_unassigned' => '미지정',
    'assign' => '지정',
    'manager_assigned' => '채권담당자가 지정됐습니다.',
    'form_title_edit' => '회수 이력 수정',
    'form_title_add' => '회수 이력 추가',

    'field' => [
        'date' => '회수일자',
        'collector' => '회수 담당자',
        'method' => '회수 방법',
        'amount' => '금액',
        'amount_attr' => '회수 금액',
        'memo' => '메모',
        'select' => '선택',
    ],
    'method' => [
        'deposit' => '입금',
        'cash' => '현금',
        'offset' => '상계',
        'other' => '기타',
    ],
    'method_deposit_full' => '입금 (final_payment 자동 생성)',
    'memo_ph' => '회수 경위·연락 이력 등',
    'btn_edit_save' => '수정 저장',
    'btn_add' => '추가',
    'saved_edit' => '회수 이력이 수정됐습니다.',
    'saved_add' => '회수 이력이 추가됐습니다.',
    'deleted' => '회수 이력이 삭제됐습니다.',
    'list_title' => '회수 이력 목록 (최신순)',
    'list_empty' => '회수 이력이 없습니다.',
    'mirror_title' => 'final_payments와 미러링됨',
    'delete_confirm' => '이 회수 이력을 삭제하시겠습니까? 미러링된 입금 기록도 함께 삭제됩니다.',
];
