<?php

// Phase 2 — 월배치 정산지급 승인큐 i18n.
return [
    'title' => '월배치 정산지급 승인',
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

    // 월배치 수동 조정란 (jin 2026-07-08)
    // 2026-08-06 (jin) — 조정 입력은 정산관리 제출 모달로 이전. 여기선 읽기 전용 표시만 남는다.
    'adjust' => [
        'title' => '조정 내역 (제출 시 확정)',
        'readonly_hint' => '조정은 정산관리에서 월배치를 제출할 때 확정됩니다. 고치려면 이 배치를 반려하고 정산관리에서 다시 제출하세요.',
        'reflected' => '조정 반영',
    ],
];
