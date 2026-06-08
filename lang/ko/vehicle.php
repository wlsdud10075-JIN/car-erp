<?php

// i18n — 차량 관리 (erp/vehicles/index). 목록 영역. (슬라이드 패널 키는 추후 추가)
return [
    'title' => '차량 관리',
    'total' => '총 :count대',
    'per_page' => ':count개씩',
    'create_btn' => '차량 등록',

    'search_placeholder' => '차량번호 · 브랜드 · 차종 · 소유자 · 수출신고번호',
    'search_btn' => '조회',
    'all_salesmen' => '담당자 전체',
    'all_buyers' => '바이어 전체',
    'filter_all' => '전체',

    'date_type' => [
        'purchase' => '매입일',
        'sale' => '판매일',
        'shipping' => '선적일',
        'bl' => 'B/L발행일',
    ],

    'columns' => '컬럼',
    'show_columns' => '표시 컬럼',
    'reset_defaults' => '기본값 복원',

    'col' => [
        'number' => '차량번호',
        'brand_model' => '브랜드/차종',
        'status' => '진행상태',
        'purchase_date' => '매입일',
        'sale_date' => '판매일',
        'shipping_date' => '선적일',
        'bl_issue_date' => 'B/L발행일',
        'salesman' => '담당자',
        'buyer' => '바이어',
        'channel' => '채널',
        'currency_rate' => '통화/환율',
        'purchase_price' => '매입가',
        'sale_price' => '판매가',
        'unpaid_amount' => '미수금',
        'unpaid_ratio' => '입금률',
    ],

    'fully_paid' => '완납',
    'deleted' => '차량이 삭제됐습니다.',
    'delete' => '삭제',
    'delete_confirm' => '차량 :number을(를) 삭제하시겠습니까?',
    'empty' => '차량이 없습니다.',

    'shipdoc_select_title' => '선적 서류 다중 선택 (수출 차량)',
    'selected' => ':count대 선택',
    'max30' => '최대 30대까지 발급 가능',
    'clear_selection' => '선택 해제',
    'shipdoc' => [
        'container_invoice_packing' => '컨테이너 Invoice&Packing',
        'container_contract' => '컨테이너 Contract',
        'roro_invoice_packing' => 'RORO Invoice&Packing',
        'roro_contract' => 'RORO Contract',
    ],
];
