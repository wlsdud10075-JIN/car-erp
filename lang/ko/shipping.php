<?php

// 선적요청 화면 (board 영업포털 → car-erp). 배치별 묶음·상태 추적.
return [
    'title' => '선적요청',
    'subtitle' => '영업이 board에서 올린 선적 지시 — 요청 묶음별 상태 추적',
    'empty' => '선적요청이 없습니다.',

    'filter' => [
        'active' => '할 일',
        'all' => '전체',
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],
    'search_ph' => '바이어·컨사이니·차량번호',
    'empty_search' => '검색 결과가 없습니다.',
    'status' => [
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],

    'requested_by' => '요청 영업',
    'vehicles_n' => ':n대',

    'tab' => [
        'shipping' => '선적 / 발급',
        'cost' => '2차 비용 (성지 면허비)',
    ],

    // 2차 비용 탭 — 면허비 묶음 n/1
    'license' => [
        'tab_hint' => '2차 정산 대기(거래완료 후 한 달) 묶음. 월은 정산 귀속월(예: 5월분 → 6/10 지급) 기준이라 정산과 맞물립니다. 면허비는 묶음당 한 덩어리로 나와 차량 수로 n/1 분배합니다. 관리는 본인 팀 묶음만 표시.',
        'pay_label' => '지급',
        'empty' => '2차 정산 대기 중인 묶음이 없습니다.',
        'batch_n' => '묶음 :n개',
        'not_entered_n' => '미기입 :n',
        'badge_not_entered' => '면허비 미기입',
        'badge_entered' => '기입됨',
        'enter_btn' => '면허비 n/1',
        'form_title' => '면허비 n/1 — :n대에 분배',
        'total_label' => '면허비 총액',
        'preview' => ':n대 · :each',
        'preview_hint' => '총액을 입력하면 n/1 미리보기가 표시됩니다.',
        'apply_btn' => '일괄 기입',
        'invalid_total' => '면허비 총액을 정확히 입력하세요.',
        'applied' => '면허비 :count대 n/1 기입 완료',
        'applied_partial' => '면허비 :ok대 기입 완료, :skip대 제외(권한/미매칭)',
    ],

    'action' => [
        'start' => '진행중으로',
        'entry_locked' => '착수 불가',
        'entry_locked_tip' => '입금 50% 미달 차량: :vehicles — 완납·관리 승인 또는 미달 차 빼고 재묶음 필요',
        'done' => '완료 처리',
        'cancel' => '취소',
        'more' => '더보기',
        'open_in_vehicles' => '차량관리에서 :count대 보기',
        'download_dereg' => '말소신청서 :count건 다운로드',
        'no_dereg' => '이 묶음에 업로드된 말소신청서가 없습니다',
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
        'bl_issued' => 'B/L번호·선박명을 묶음 차량에 일괄 기입했습니다.',
        'decl_applied' => '수출신고번호를 묶음 :count대에 일괄 기입했습니다.',
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
        'issue' => 'B/L번호·선박명 기입',
        'issue_title' => 'B/L번호·선박명 — 묶음 :n대 일괄 기입',
        'field_type' => 'B/L 방식',
        'field_number' => 'B/L 번호',
        'field_container' => '컨테이너 No',
        'field_vessel' => '선박명(VSL)',
        'apply' => '일괄 기입',
        'cancel' => '닫기',
        'not_fully_paid' => '완납 후 기입 가능 (미완납 묶음)',
        'blocked_vehicles' => 'B/L 발행 불가 — 미완납 차량: :vehicles. 100% 완납 또는 관리 승인(B/L 발행) 후 발행하세요.',
        'requested_hint' => '영업 요청 방식',
        'guard_mismatch' => '⚠ 이중가드 — 영업 요청은 :req 인데 현재 :cur 로 선택됨. B/L 문서 업로드 전 확인하세요.',
    ],

    // 🔒 (나)+(a) 선적 진입 락 — 묶음 착수 차단
    'lock' => [
        'entry_blocked' => '선적 착수 불가 — 입금 50% 미달 차량: :vehicles. 50% 이상 입금 또는 관리 승인 후 착수하세요. (묶음 전체 대기 — 급하면 미달 차량을 빼고 다시 묶으세요.)',
    ],

    // 수출신고번호 일괄 기입 (묶음 공유 1개 → 전체)
    'decl' => [
        'enter' => '수출신고번호 기입',
        'title' => '수출신고번호 — 묶음 :n대 일괄 기입',
        'field_number' => '수출신고번호',
        'placeholder' => '예: 12345-67-890123X',
        'hint' => '묶인 차량 전체에 같은 번호가 기입됩니다.',
        'apply' => '일괄 기입',
        'cancel' => '닫기',
        'invalid' => '수출신고번호를 입력하세요.',
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
