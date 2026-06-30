<?php

// 선적요청 화면 (board 영업포털 → car-erp). 배치별 묶음·상태 추적.
return [
    'title' => '선적요청',
    'subtitle' => '영업이 board에서 올린 선적 지시 — 요청 묶음별 상태 추적',
    'empty' => '선적요청이 없습니다.',

    'filter' => [
        'all' => '전체',
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],
    'status' => [
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],

    'requested_by' => '요청 영업',
    'vehicles_n' => ':n대',

    'action' => [
        'start' => '진행중으로',
        'done' => '완료 처리',
        'cancel' => '취소',
        'open_in_vehicles' => '차량관리에서 :count대 보기',
    ],

    'confirm' => [
        'cancel' => '이 선적요청(:n대)을 취소합니다. 영업이 board에서 다시 요청할 수 있습니다. 진행할까요?',
    ],

    'doc' => [
        'label' => '묶음 서류',
        'invoice_packing' => 'Invoice & Packing',
        'contract' => '계약서',
    ],

    'toast' => [
        'updated' => '선적요청 상태를 변경했습니다.',
        'cancelled' => '선적요청을 취소했습니다.',
        'bl_issued' => 'B/L을 묶음 차량에 발급(일괄 기입)했습니다.',
        'change_accepted' => '변경요청을 수락하여 묶음을 해제했습니다. 영업이 재구성할 수 있습니다.',
        'change_rejected' => '변경요청을 반려했습니다.',
    ],

    // B/L 단계 (오리지널/써랜더 · 발급)
    'bl' => [
        'status_requested' => 'B/L요청',
        'status_issued' => 'B/L발급완료',
        'type' => [
            'original' => '오리지널',
            'surrender' => '써랜더',
        ],
        'issue' => 'B/L 발급',
        'issue_title' => 'B/L 발급 — 묶음 :n대 일괄 기입',
        'field_type' => 'B/L 방식',
        'field_number' => 'B/L 번호',
        'field_container' => '컨테이너 No',
        'field_vessel' => 'VSL',
        'apply' => '발급(일괄 기입)',
        'cancel' => '닫기',
        'not_fully_paid' => '완납 후 발급 가능 (미완납 묶음)',
        'requested_hint' => '영업 요청 방식',
    ],

    // 묶음 미수
    'fin' => [
        'fully_paid' => '완납',
        'unpaid' => '미수',
        'fx_missing' => '환율 미입력 :n대',
        'surrender_warning' => '⚠ 써랜더 + 미완납 — 실물 leverage 포기 주의',
    ],

    // 변경요청 (영업이 in_progress 묶음에 보낸 명시 변경요청)
    'change' => [
        'flag' => '변경요청',
        'accept' => '수락(해제)',
        'reject' => '반려',
        'confirm_accept' => '변경요청을 수락하면 이 묶음이 해제됩니다. 영업이 재구성할 수 있습니다. 진행할까요?',
    ],
];
