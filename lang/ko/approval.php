<?php

// i18n — 승인 큐 (erp/approvals). 모델 라벨(action_label/status_label/ApprovalRequest::TYPES)은 미번역(모델 레이어).
return [
    'title' => '승인 큐',
    'subtitle' => '대기 :count건 · 6 액션 통합 (같은 바이어 미수·정산 지급·민감 액션·50% 룰 예외·자금 이체·이체 취소)',
    'all_actions' => '액션 전체',

    'filter' => [
        'pending' => '대기',
        'approved' => '승인',
        'rejected' => '거부',
        'all' => '전체',
    ],
    'col' => [
        'created' => '생성일',
        'action' => '액션',
        'requester' => '요청자',
        'target' => '대상',
        'reason' => '사유',
        'status' => '상태',
        'decider' => '결정자',
        'handle' => '처리',
    ],
    't' => [
        'source' => '출처',
        'target' => '대상',
        'vehicle' => '차량',
        'buyer' => '바이어 #:id',
        'unassigned' => '(미지정)',
        'overlap' => '미수 :count대 ₩:amount',
        'overlap_vehicles' => '미수 차량:',
        'void' => '⊘ 이체 #:id 취소',
        'restore' => '원상복구',
    ],
    'tstatus' => [
        'approved_awaiting' => '관리 승인 (재무 처리 대기)',
        'voided_awaiting' => '취소 승인 (재무 처리 대기)',
        'void_rejected' => '재무 취소 거부',
        'executed' => '이체 완료',
        'voided' => '이체 취소 완료',
        'finance_rejected' => '재무 거부',
    ],

    'approve' => '승인',
    'reject' => '거부',
    'handled' => '처리됨',
    'empty' => '조건에 맞는 승인 요청이 없습니다.',

    'modal' => [
        'approve_title' => '승인 확인',
        'reject_title' => '거부 사유 입력',
        'approve_desc' => '이 요청을 승인하시겠습니까? 사유는 선택입니다.',
        'reject_desc' => '거부 사유를 5자 이상 입력해야 합니다.',
        'memo_ph' => '메모 (선택)',
        'reject_ph' => '거부 사유 (필수)',
    ],

    'toast' => [
        'no_perm' => '승인 권한이 없습니다.',
        'already' => '이미 처리되었거나 존재하지 않는 요청입니다.',
        'self' => '본인이 요청한 안건은 본인이 처리할 수 없습니다 (SoD 위반).',
        'reject_min' => '거부 사유를 5자 이상 입력하세요.',
        'fail' => '처리 실패: :error',
        'done_approve' => '승인 + 액션 실행 완료.',
        'done_reject' => '거부 완료.',
    ],
];
