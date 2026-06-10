<?php

// i18n — 영업담당자 캐시플로우 (erp/salesmen/cashflow). 테이블 헤더는 vehicle.col, 진행상태는 domain.progress.
return [
    'title' => ':name — 캐시플로우',
    'date_from' => '매입일 시작',
    'date_to' => '매입일 종료',
    'kpi_vehicles' => '담당 차량',
    'unit' => '대',
    'kpi_purchase_total' => '매입가 합계',
    'kpi_purchase_unpaid' => '매입 미지급',
    'kpi_sale_unpaid' => '판매 미입금',
    'kpi_carryover' => '미청산 이월',
    'carryover_sub' => '정산에 흡수 안 된 환차 이월 잔액',
    'carryover_to_pay' => '지급 대기',
    'carryover_to_collect' => '청구 대상',
    'carryover_clear_btn' => '이월 청산',
    'carryover_clear_confirm' => '미청산 이월 ₩:amount 을(를) :action(으)로 처리하고 0으로 만듭니다. 퇴사자/관계종료 정리용입니다. 진행할까요?',
    'carryover_cleared' => '미청산 이월 ₩:amount 청산 완료 (잔액 0).',
    'carryover_already_zero' => '청산할 미청산 이월이 없습니다 (이미 0).',
    'none' => '없음',
    'paid' => '완납',
    'empty' => '해당 기간에 담당 차량이 없습니다.',
    'm_purchase_date' => '매입일:',
    'm_purchase_price' => '매입가:',
    'm_unpaid' => '미지급:',
    'm_sale_unpaid' => '미입금:',
];
