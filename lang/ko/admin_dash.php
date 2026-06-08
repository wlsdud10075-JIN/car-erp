<?php

// i18n — 관리자 대시보드 (admin/dashboard). risk=receivable.risk 재사용.
return [
    'title' => '관리자 대시보드',
    'subtitle' => '매출 / 차량 진행 KPI · 기간별 비즈니스 지표',

    'date_type' => [
        'purchase' => '매입일 기준',
        'sale' => '판매일 기준',
        'shipping' => '선적일 기준',
        'completed' => '거래완료일 기준',
    ],
    'date_type_short' => [
        'purchase' => '매입일',
        'sale' => '판매일',
        'shipping' => '선적일',
        'completed' => '거래완료일',
    ],
    'search' => '조회',
    'widget_settings' => '위젯 설정',
    'quick_select' => '빠른 선택:',
    'quick' => [
        'this_month' => '이번달',
        'last_month' => '전월',
        'this_year' => '올해',
        'last_year' => '전년도',
    ],
    'quick_hint' => '→ 조회 버튼을 눌러 적용',

    'tab' => [
        'sales' => '영업',
        'clearance' => '수출통관',
        'settlement' => '재무',
        'receivable' => '채권',
    ],

    'unit_count' => '대',
    'unit_won' => '원',
    'detail' => '상세 →',
    'salesman_fallback' => '담당자 #:id',
    'month_labels' => ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
    'range_limited' => '한정',

    // 매출 KPI
    'kpi_vehicles' => '기간 차량 수',
    'kpi_purchase_total' => '기간 매입가 합계',
    'kpi_sale_accrual' => '이달 판매 (발생)',
    'kpi_sale_accrual_sub' => '판매 등록 총액',
    'kpi_sale_count_link' => ':count대 →',
    'kpi_sale_accrual_note' => '발생주의 — 등록된 모든 판매가 KRW 환산 합',
    'kpi_cash' => '이달 현금 회수',
    'kpi_cash_sub' => '실제 입금액',
    'kpi_cash_note' => '현금주의 — row 별 입금 시점 환율 합산',
    'kpi_unpaid' => '이달 미수금',
    'kpi_unpaid_sub' => '받을 돈',
    'kpi_receivable_link' => '채권관리 →',
    'kpi_unpaid_note' => '발생 − 회수 = 미수 (자동 정합)',

    'progress_title' => '진행 단계별 차량 수',

    // 통관 KPI
    'clearance_stuck' => '통관 정체 차량',
    'clearance_stuck_days' => ':days일 경과',
    'clearance_stuck_note' => '판매완료·완납인데 수출신고서 미발급',
    'clearance_unfiled' => '수출신고서 미업로드',
    'clearance_unfiled_badge' => '통관중 단계',
    'clearance_unfiled_note' => '통관 바이어·선적일 있지만 문서 NULL',
    'forwarder_top' => '포워딩사 TOP 5 (통관·선적 진행)',
    'forwarder_empty' => '진행 차량 없음',

    // 정산 KPI
    'settle_payout_pending' => '정산 지급 대기 총액',
    'settle_payout_badge' => '확정·미지급',
    'settle_payout_note' => '확정 상태 정산의 실지급액 합계',
    'settle_margin_rate' => '정산 마진율 평균',
    'settle_margin_badge' => '지급완료 한정',
    'settle_margin_note' => '총마진 / 판매금원화 평균',
    'settle_monthly_title_pre' => '인원별 정산지급액 월별 (',
    'settle_monthly_title_post' => '년)',
    'settle_monthly_note' => '지급완료 정산만 집계. 상위 8명 누적. 스냅샷 우선 (소급 보정 방지).',

    // 담당자 성과
    'salesman_count_title' => '담당자별 판매 대수 (상위 10명)',
    'salesman_count_note' => '기준',
    'salesman_krw_title' => '담당자별 판매 금액 KRW (상위 10명)',
    'salesman_krw_note' => 'tooltip에 평균 판매가 표시',

    // 월별 차트
    'monthly_count_title_pre' => '월별 차량 대수 (',
    'monthly_count_title_post' => '년)',
    'monthly_count_note' => '매입·판매·거래완료(B/L 발행) 컬럼별 월 분포',
    'monthly_sales_title_pre' => '월별 판매가 합계 KRW (',
    'monthly_sales_title_post' => '년)',
    'monthly_sales_note' => 'sale_date 기준. 외화는 sale_price × exchange_rate (환율 0/NULL 제외)',

    // 채권 탭
    'recv_total_unpaid' => '총 미수금',
    'recv_total_unpaid_note' => '환율 미입력 외화 제외',
    'recv_before' => '선적전 미수금',
    'recv_before_note' => ':count대 · 매입~판매완료 단계',
    'recv_after' => '선적후 미수금',
    'recv_after_note' => ':count대 · 통관~선적완료 단계',
    'recv_deposit' => '디파짓 (적립금 사용분)',
    'recv_deposit_note' => ':count대 · savings_used > 0',
    'recv_salesman_top' => '미수금 상위 담당자 TOP 10',
    'recv_buyer_top' => '미수금 상위 바이어 TOP 10',
    'recv_empty' => '미수금 차량 없음',
    'recv_col_salesman' => '담당자',
    'recv_col_buyer' => '바이어',
    'recv_col_unpaid' => '미수금',
    'recv_col_rate' => '미납률',
    'recv_col_vehicle' => '차량',
    'recv_link_card' => '회수 이력·세부 차량별 정보는 채권관리 화면에서 관리됩니다.',
    'recv_link_btn' => '채권관리 화면으로 이동 →',

    'footer_pre' => 'ⓘ 미수금·회수 이력·채권 위험도는 ',
    'footer_link' => '채권관리 화면',
    'footer_post' => '에서 확인하세요.',

    // 위젯 설정 패널
    'widget_panel_hint' => '표시할 위젯을 선택하세요. 설정은 이 브라우저에 저장됩니다.',
    'widget' => [
        'kpi' => 'KPI 카드 (4개)',
        'progress' => '진행 단계별 차량 수',
        'monthly' => '월별 차트 (대수·판매가)',
        'salesman' => '담당자별 성과 차트',
        'settlement' => '정산 KPI (지급액·마진)',
        'clearance' => '통관 KPI (정체·미업로드·포워딩사)',
    ],

    // 차트 데이터셋 라벨 (JS)
    'chart' => [
        'purchase' => '매입',
        'sale' => '판매',
        'completed' => '거래완료',
        'sales_krw' => '판매가 KRW',
        'sale_count' => '판매 대수',
        'sale_amount_krw' => '판매 금액 KRW',
        'tip_total' => '합계 ₩ ',
        'tip_avg' => '평균 ₩ ',
    ],
];
