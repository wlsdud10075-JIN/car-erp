<?php

// 바이어 정산현황 (jin 2026-09-05) — 기획 docs/design/buyer-cash-ledger.md
// 🚫 줄여서 「정산」이라고 쓰지 말 것. 이 ERP 의 「정산」은 담당자 지급이다.
return [
    'title' => '바이어 정산현황',
    'subtitle' => '바이어가 보낸 돈이 어디로 갔고, 아직 받을 돈이 어느 묶음에 얼마 남았는지.',

    'pick_buyer' => '바이어',
    'pick_placeholder' => '바이어를 고르세요',
    'pick_first' => '바이어를 고르면 현금·미수 현황이 나옵니다.',
    'no_buyers' => '미수가 있거나 현금이 남은 바이어가 없습니다.',

    'cash_title' => '남은 현금 (통화별)',
    'received' => '받은 돈',
    'allocated' => '차량에 쓴 돈',
    'remaining' => '남은 현금',
    'no_cash' => '기재된 입금이 없습니다.',

    'usage_title' => '현금 사용 내역',
    'usage_note' => '입금 한 건이 어느 차량에 얼마씩 쓰였는지. 검색과 무관하게 전부 보여줍니다 — 여기 차량은 아래 「미수 차량」에 없을 수 있습니다(현금으로 완납된 차).',
    'not_used_yet' => '아직 쓰이지 않았습니다.',

    'unpaid_title' => '받을 돈 (통화별)',
    'no_unpaid' => '남은 미수가 없습니다.',
    'unpaid_note' => '미수가 남은 차량 :count대',

    'groups_title' => '묶음별 남은 금액',
    'unassigned' => '미지정',
    'axis' => [
        'container' => '컨테이너번호',
        'declaration' => '수출신고번호',
        'bl' => 'B/L번호',
        'vessel' => '선박명',
    ],

    'vehicles_title' => '미수 차량',
    'col_vehicle' => '차량번호',
    'col_vin' => '차대번호',
    'col_progress' => '진행상태',
    'col_total' => '총판매가',
    'col_received' => '받은 돈',
    'col_unpaid' => '남은 금액',
    'col_count' => '대수',
    'col_vehicles' => '차량',

    'export' => '엑셀 내려받기',
];
