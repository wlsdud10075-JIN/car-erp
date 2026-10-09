<?php

// i18n — 월정산 사람 카드 (components/payout/person-card · totals). 월정산 v3 (2026-10-09).
//   정산관리 담당자별 합계 · 월정산 · 폰 승인 링크가 같은 카드를 쓴다. 숫자는 PersonPayoutBreakdown 한 곳.
return [
    'count' => ':n대',
    'changed' => '변경됨',
    'times' => '배',
    'open_hint' => '눌러서 계산 단계 펼치기',

    // 접힌 줄
    'margin_after_pay' => '급여공제후 마진',
    'excess_ratio' => '급여 대비 초과 배율',
    'contribution' => '회사 기여',
    'payout' => '실지급액',
    'payroll_total' => '지급합계',
    'share' => '지분율',

    // 왼쪽 — 회사 손익 산출
    'ledger_company' => '회사 손익 산출',
    'equiv_sale_rate' => '당월 프리랜서 공식 정산 (판매환율)',
    'fx_primary' => '① 1차 환차 (실입금환율 반영)',
    'carry_employee' => '이월·2차 마감분 (프리랜서였다면)',
    'carry_freelance' => '이월·2차 마감분',
    'fx_secondary' => '그중 ② 2차 환차',
    'adj_manual' => '수기 추가정산',
    'adj_loss' => '매입취소 손실',
    'equiv_total' => '프리랜서 환산 합계',
    'minus_payout' => '− 실지급액',
    'total_margin' => '총마진',
    'minus_shipping' => '− 발송비 (회사 선지출)',
    'contribution_formula' => '회사 기여 = 총마진 − 실지급액 − 발송비',
    'freelance_note' => '프리랜서는 환산액이 곧 지급액이라 「급여공제후 마진」 대신 회사 기여를 봅니다.',

    // 오른쪽 — 담당자 지급
    'ledger_person' => '담당자 지급',
    'payroll' => '급여 지급합계',
    'payroll_missing' => '미입력',
    'payroll_missing_hint' => '사내직원관리에서 이 달 급여 항목을 적어 주세요. 비어 있으면 0원으로 계산됩니다.',
    'settlement_pay' => '정산금',
    'settlement_pay_per_unit' => '건당',
    'settlement_pay_tier' => '차등',
    'adj_carry' => '이월 조정',
    'incentive' => '추가 인센티브',
    'incentive_preview' => '월정산 제출 때 입력',
    'deposit' => '예치금 보유',
    'deposit_hint' => '지급액과 무관, 표시만',
    'vehicles' => '차량별 보기',

    // 검차직원
    'inspector_only' => '급여만',
    'inspector_in_transfer' => '송금 총액',
    'inspector_in_transfer_v' => '포함',
    'inspector_in_net' => '회사 순이익',
    'inspector_in_net_v' => '「공통 인건비」로 차감',
    'inspector_in_share' => '지분율 계산',
    'inspector_in_share_v' => '제외',
    'where_reflected' => '반영 위치',

    'unsupported' => '이 회사의 정산 공식(구간표)은 환산 카드를 아직 지원하지 않습니다 — 정산·급여·인센티브와 실지급액만 표시합니다.',

    // 합계 띠
    'totals' => [
        'company_net' => '회사 순이익 (급여 차감 후)',
        'vehicles' => '총 판매 차량',
        'equiv_sum' => '프리랜서 환산 합계',
        'transfer_total' => '송금 총액 (급여 포함)',
        'settlement_total' => '정산·조정 총액 (승인 금액)',
        'common_labor' => '공통 인건비 (검차직원)',
        'contribution_sum' => '개인 회사 기여 합',
        'hint' => '회사 순이익 = Σ(총마진 − 실지급 − 발송비) − 공통 인건비. 승인하는 금액은 종전처럼 「정산·조정 총액」입니다.',
    ],
];
