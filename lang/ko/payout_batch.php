<?php

// Phase 2 — 월정산 정산지급 승인큐 i18n.
return [
    'title' => '월정산 정산지급 승인',
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
    'type_ratio' => '프리랜서 :ratio%',
    'type_per_unit' => '사내직원 건당',
    'empty' => '배치가 없습니다.',
    'notify' => [
        'approved' => '승인 처리됐습니다.',
        'rejected' => '반려됐습니다. 정산은 재배치할 수 있습니다.',
        'reason_required' => '반려 사유를 입력하세요.',
    ],

    // 월정산 수동 조정란 (jin 2026-07-08)
    // 2026-08-06 (jin) — 조정 입력은 정산관리 제출 모달로 이전. 여기선 읽기 전용 표시만 남는다.
    // 📨 승인요청 재전송 (jin 2026-10-07)
    'resend' => [
        'btn' => '승인요청 재전송',
        'btn_wait' => ':min분 뒤 재전송',
        'hint' => '현재 승인 단계의 승인자에게 승인요청 알림톡을 다시 보냅니다(바로가기 링크 새로 생성). 배치 내용은 그대로입니다.',
        'confirm' => ':role 에게 승인요청 알림톡을 다시 보낼까요?',
        'done' => ':role 에게 승인요청을 다시 보냈습니다.',
        'wait' => '방금 보냈습니다 — :min분 뒤에 다시 보낼 수 있습니다.',
        'not_pending' => '승인 대기 중인 배치만 다시 보낼 수 있습니다.',
        'forbidden' => '관리·업무관리자(월정산 제출 권한자)만 다시 보낼 수 있습니다.',
        'state_ok' => '발송성공',
        'state_fail' => '발송실패',
    ],
    'adjust' => [
        'title' => '조정 내역 (제출 시 확정)',
        'readonly_hint' => '조정은 정산관리에서 월정산을 제출할 때 확정됩니다. 고치려면 이 배치를 반려하고 정산관리에서 다시 제출하세요.',
        'reflected' => '조정 반영',
    ],

    // 📊 마진율 · 기본급 · 월수령액 (jin 2026-09-18) — 전부 **표시 전용**.
    //    🚫 지급 총액·회사이익은 바뀌지 않는다. 기본급은 급여라 정산이 아니다.
    // 🪜 월정산 v3 결재선 (2026-10-09)
    'steps' => [
        'title' => '결재선',
        'next' => '다음 결재: :who',
        'submitted' => '상신',
        'pending' => '대기',
        'approved' => '결재',
        'rejected' => '반려',
        'note_ph' => '의견 한 줄 (결재 내역에 남습니다)',
        'log_title' => '결재 내역',
        'change_incentive' => '추가 인센티브',
        'change_adjustment' => '조정',
        'change_payroll' => '급여 항목',
        'changed_hint' => '노란색 = 상신 뒤 바뀐 칸. 결재는 멈춘 단계부터 이어집니다. 이미 나간 알림톡의 총액은 그 시점 값입니다.',
        'rejected_kept' => '반려된 월정산입니다 — 제출 당시 내용을 그대로 보존합니다. 정산은 정산관리로 돌아갔고, 다시 올리면 새 월정산이 됩니다.',
        'incentive_title' => '추가 인센티브 (결재 중 수정)',
        'incentive_add' => '인센티브 추가',
        'incentive_added' => ':name 에게 인센티브 :amount원을 더했습니다. 결재는 지금 단계부터 이어집니다.',
        'incentive_removed' => '인센티브를 삭제했습니다.',
        'incentive_invalid' => '담당자·금액·사유를 모두 입력하세요 (금액 0 불가).',
        'edit_forbidden' => '결재 중 금액 수정 권한이 없습니다 (제출 권한자·최고관리자).',
        'approve_note' => '의견',
    ],

    'margin' => [
        'label' => '마진율',
        'batch_total' => '이 배치 전체',
        'none' => '—',
        'hint' => '총마진 ÷ 판매금원화. 내수 차량은 마진율이 없어 「—」로 두고 합계에서도 빠집니다.',
        'pay' => [
            'base_salary' => '기본급',
            'settlement' => '정산',
            'take_home' => '월수령액',
            'deposit' => '예치금 보유',
            'base_salary_total' => '기본급 합계',
            'expected_transfer' => '이달 송금 예상',
            'expected_hint' => '지급 총액 + 사내직원 기본급(이 배치에 있는 사람과, 이달 정산이 없어도 재직 중인 기본급 직원). 통장에서 나갈 돈을 한 번에 보기 위한 표시로, 승인·회사이익 계산에는 들어가지 않습니다.',
            'salary_only' => '정산 없음 · 기본급만',
        ],
    ],
];
