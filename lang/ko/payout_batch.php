<?php

// Phase 2 — 월배치 정산지급 승인큐 i18n.
return [
    'title' => '월배치 정산지급 승인',
    'cancel_loss' => [
        'title' => '매입취소 손실 (프리랜서 부담 몫)',
        'empty' => '해당 기간에 미반영 매입취소 손실이 없습니다.',
        'salesman' => '담당자',
        'vehicles' => '차량 (담당자 몫)',
        'subtotal' => '담당자 몫 소계',
        'grand_total' => '전체 합계',
        'prefill' => '조정에 넣기',
        'settle' => '반영 표시',
        'settle_confirm' => '이 담당자 손실을 반영됨으로 표시합니다. 요약에서 제외됩니다. 진행할까요?',
        'note' => '소계(−금액)를 해당 배치의 담당자 조정에 입력하고, 그 배치가 최종 승인된 뒤 「반영 표시」를 누르세요(반려되면 표시하지 말 것 — 손실 누락 방지). 이중 청구는 「반영 표시」로 막습니다. 프리랜서만 부담(사내직원 손실은 회사 전액).',
        'reason' => '매입취소 손실분담: :plates',
        'pick_batch' => '먼저 대상 배치에서 「조정 추가」를 누르세요.',
        'settled' => '반영됨으로 표시했습니다.',
    ],
    'subtitle' => '한 달치 확정 정산을 묶어 승인 사다리(업무관리자→대표)로 지급 처리',
    'status' => [
        'pending' => '대기',
        'approved' => '승인·지급',
        'rejected' => '반려',
        'cancelled' => '취소',
    ],
    'count' => ':n건',
    'submitter' => '제출',
    'next_level' => ':role 승인 차례',
    'confirm_approve' => '이 배치를 승인합니까? (대표 최종 승인이면 전 정산이 즉시 지급 처리됩니다)',
    'approve' => '승인',
    'reject' => '반려',
    'reject_reason_ph' => '반려 사유 (필수)',
    'reject_confirm' => '반려',
    'rejected_reason' => '반려 사유: :reason',
    'no_salesman' => '담당자 미지정',
    'empty' => '배치가 없습니다.',
    'notify' => [
        'approved' => '승인 처리됐습니다.',
        'rejected' => '반려됐습니다. 정산은 재배치할 수 있습니다.',
        'reason_required' => '반려 사유를 입력하세요.',
    ],

    // 월배치 수동 조정란 (jin 2026-07-08)
    'adjust' => [
        'title' => '수동 조정 (과지급 환수·특별지급 등)',
        'salesman' => '담당자',
        'amount' => '금액 (음수는 - )',
        'reason' => '사유 (필수)',
        'add' => '추가',
        'add_line' => '조정 추가',
        'hint' => '음수 = 환수·공제 / 양수 = 특별지급. 담당자 소계와 배치 총액에 함께 반영됩니다(개별 정산 원본은 안 바뀜). 대표 승인 시 함께 승인됩니다.',
        'invalid' => '담당자·0이 아닌 금액·사유를 모두 입력하세요.',
        'added' => '조정을 추가했습니다.',
        'removed' => '조정을 삭제했습니다.',
        'reflected' => '조정 반영',
    ],
];
