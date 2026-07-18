<?php

// i18n — 업무/일반사용자 대시보드 (erp/dashboard).
return [
    'view_label' => [
        '영업' => '내 영업 업무',
        '수출통관' => '내 수출통관 업무',
        '재무' => '내 재무 업무',
        '관리' => '내 관리 업무',
    ],

    'mode_by_salesman' => '담당자별',
    'mode_by_role' => '역할별',
    'salesman' => '담당자',
    'all' => '전체',

    'salesman_missing_title' => '담당자 정보가 연결되지 않았습니다',
    'salesman_missing_sub' => '관리자에게 본 계정의 salesman 연결을 요청하세요.',

    'fx_title' => '실시간 환율',
    'fx_sub' => '(송금받을때 · KRW 기준 · 1h 캐시)',
    'fx_refresh' => '새로고침',
    'fx_fail' => '⚠ 실시간 환율 조회 실패 — 잔금N+ 추가 시 수동 입력하세요.',
    'fx_refreshed' => '환율 새로고침 완료',

    'actions_title' => '처리 필요 항목',
    'empty' => [
        '수출통관' => ['title' => '처리 대기 항목 없음', 'sub' => '수출통관/선적/DHL 흐름 정상'],
        '재무' => ['title' => '회수·재무 대기 없음', 'sub' => '모든 채권/정산 정상'],
        'default' => ['title' => '처리할 항목이 없습니다', 'sub' => '모든 작업이 최신 상태입니다'],
    ],

    'active_title' => '진행중 차량',
    'view_all' => '전체 보기 →',
    'no_active' => '진행중인 차량이 없습니다.',
    'none' => '없음',
    'unit_vehicle' => '대',
    'unit_count' => '건',
    'per_page' => ':count대',
    'subtitle_only' => ':name 한정',
    'subtitle_all' => '전체 차량',

    'col' => [
        'number' => '차량번호',
        'salesman' => '담당자',
        'status' => '진행상태',
        'next' => '다음 할일',
        'purchase_date' => '매입일',
        'unpaid_purchase' => '미지급',
        'unpaid_sale' => '미입금',
    ],

    // 월 라벨 (KPI 힌트용). en 은 'M' 포맷(Jun) 사용.
    'month_label' => ':nn월',

    'kpi' => [
        'sales' => [
            'active' => ['l' => '현재 진행중', 'h' => '거래완료 제외'],
            'month_buy' => ['l' => '이달 매입', 'h' => ':month 매입 기준'],
            'month_sale' => ['l' => '이달 판매 등록', 'h' => ':month 판매 등록 (입금 여부 무관)'],
            'month_done' => ['l' => '이달 거래완료', 'h' => ':month 완료 기준'],
        ],
        'clearance' => [
            'derg_wait' => ['l' => '말소 대기', 'h' => '매입가 입금 완료 → 말소 미처리'],
            'clr_req_wait' => ['l' => '통관 신청 대기', 'h' => '판매 완납 → 면장 미업로드'],
            'decl_wait' => ['l' => '수출신고서 업로드 대기', 'h' => '통관중'],
            'ship_wait' => ['l' => '선적 처리 대기', 'h' => '면장 완료 → 반입지 미입력'],
            'dhl_wait' => ['l' => 'DHL 발송 대기', 'h' => 'B/L 발행 → 미신청'],
        ],
        'settlement' => [
            'pur_unpaid' => ['l' => '매입 미지급 총액', 'h' => '진행중 매입 잔금 합계'],
            'sale_unpaid' => ['l' => '판매 미입금 총액', 'h' => '환율 입력 차량만 합산'],
            'wait' => ['l' => '정산 대기', 'h' => 'pending 상태'],
            'fx_missing' => ['l' => '환율 미입력 외화', 'h' => '외화 판매 → 환율 없음'],
            'blocked' => ['l' => '정산 차단 (거래완료 미수)', 'h' => '거래완료지만 미수금 남음 → :amount 받아야 정산 가능'],
        ],
        'management' => [
            'appr_wait' => ['l' => '승인 대기', 'h' => '4 액션 통합 (큐 14-3에서 활성화)'],
            'settle_wait' => ['l' => '정산 대기', 'h' => 'pending + confirmed'],
            'risk' => ['l' => '채권 위험', 'h' => '위험·심각 등급'],
            'clr_stuck' => ['l' => '통관 정체', 'h' => '판매완료 30일+ → 면장 미업로드'],
        ],
    ],

    'act' => [
        'sales' => [
            'purchase_unpaid' => ['l' => '매입 미지급', 'd' => '매입가 입력 후 잔금 미지급'],
            'sale_unpaid' => ['l' => '판매 미입금', 'd' => '판매 후 미회수 금액 존재'],
            'clearance_needed' => ['l' => '수출통관 신청 필요', 'd' => '판매 완납 → 면장서류 미업로드'],
            'shipping_needed' => ['l' => '선적 처리 필요', 'd' => '수출통관 완료 → B/L 미처리'],
            'dhl_needed' => ['l' => 'DHL 발송 대기', 'd' => '선적 완료 → DHL 미신청'],
            'settlement_wait' => ['l' => '정산 대기', 'd' => '정산 방식 미입력 또는 확인 필요'],
            'freight_confirm' => ['l' => '인코텀즈 확정 필요', 'd' => '완납했으나 인코텀즈(FOB/CFR)·운임비 미확정 → 정산 대기'],
            'cancel_unpaid' => ['l' => '매입취소 미수', 'd' => '위약금 미수령 취소건 — 수금 또는 미수 마감'],
        ],
        'clearance' => [
            'deregistration_needed' => ['l' => '말소 처리 필요', 'd' => '매입 완료 → 말소 미처리'],
            'clearance_request_needed' => ['l' => '통관 신청 필요', 'd' => '판매 완납 → 면장 미업로드'],
            'clearance_info_missing' => ['l' => '통관 바이어/일자 누락', 'd' => '판매 진입 → export_buyer 또는 shipping_date 없음'],
            'eta_missing' => ['l' => '데이터 보정 — 도착일(ETA) 없음', 'd' => '선적했는데 도착일 미입력 → 채워야 통관서류 알람 예약됨'],
            'forwarding_missing' => ['l' => '포워딩사 미지정', 'd' => '통관 진입 → forwarding 없음'],
            'export_declaration_upload_needed' => ['l' => '수출신고서 업로드', 'd' => '통관중 → 신고서 없음'],
            'shipping_process_needed' => ['l' => '선적 처리 필요', 'd' => '판매 완료 → 반입지 미입력'],
            'bl_upload_needed' => ['l' => 'B/L 업로드 필요', 'd' => '통관 완료 → B/L 미업로드'],
            'dhl_dispatch_needed' => ['l' => 'DHL 발송 대기', 'd' => '거래완료 → 미신청'],
        ],
        'settlement' => [
            'purchase_unpaid' => ['l' => '매입 미지급', 'd' => '매입가 입력 → 잔금 미지급'],
            'sale_unpaid' => ['l' => '판매 미입금', 'd' => '판매 → 미회수 잔존'],
            'exchange_rate_missing' => ['l' => '환율 입력 필요', 'd' => '외화 판매 → 환율 미입력'],
            'settlement_create_needed' => ['l' => '정산 생성 필요', 'd' => '거래완료 → settlement 없음'],
            'settlement_confirm_needed' => ['l' => '정산 확정 필요', 'd' => 'settlement = pending'],
            'settlement_pay_needed' => ['l' => '정산 지급 필요', 'd' => 'settlement = confirmed'],
            'payout_held' => ['l' => '미수로 지급보류', 'd' => '확정됐지만 미수 있어 지급 보류 → 완납 후 지급'],
            'receivable_risk' => ['l' => '채권 위험', 'd' => '회수 위험·심각 등급'],
        ],
        'management' => [
            'approval_wait' => ['l' => '승인 대기 항목', 'd' => '큐 14-3 활성화 예정'],
            'settlement_confirm_needed' => ['l' => '정산 확정 필요', 'd' => 'settlement = pending → 승인 후 paid'],
            'settlement_pay_needed' => ['l' => '정산 지급 필요', 'd' => 'settlement = confirmed → 승인 후 paid'],
            'receivable_risk' => ['l' => '채권 위험', 'd' => '회수 위험·심각 → 추가 한도 승인 검토'],
            'clearance_stuck' => ['l' => '통관 정체', 'd' => '판매완료 30일+ → 진행 점검'],
        ],
    ],
];
