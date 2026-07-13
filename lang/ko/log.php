<?php

// i18n — 로그 화면 (문서 접근 로그 + 감사 로그). chrome 만 번역.
// ColumnLabel(model/column/action)·config('column_labels')·DocumentAccessLog 라벨은 DB 기술 라벨이라 미번역.
return [
    // 문서 접근 로그
    'doc_title' => '문서 다운로드 감사 로그',
    'doc_subtitle' => '개인정보보호법 §29 안전조치 — RRN 포함 서류 접근 기록. 총 :count건',
    'doc_search' => '접근자 · 차량번호',
    'all_doc_types' => '전체 서류 종류',
    'doc_empty' => '접근 로그가 없습니다.',
    'doc_col' => [
        'time' => '시각',
        'accessor' => '접근자',
        'vehicle' => '차량',
        'document' => '서류',
        'ip' => 'IP',
    ],

    // 감사 로그
    'audit_title' => '감사 로그',
    'audit_subtitle' => '변경 추적 (큐 11-4 도입 이후 — 그 이전 액션은 미기록).',
    'audit_search' => '차량번호 · 처리자 이름',
    'total' => '총 :count 건',
    'all_users' => '사용자 전체',
    'all_actions' => '액션 전체',
    'all_columns' => '컬럼 전체',
    'reset_filters' => '필터 초기화',
    'system' => '시스템',
    'audit_empty_filtered' => '조회 조건에 일치하는 감사 로그가 없습니다.',
    'audit_empty' => '감사 로그가 없습니다.',
    'audit_col' => [
        'time' => '시각',
        'user' => '사용자',
        'target' => '대상',
        'action' => '액션',
        'column' => '컬럼',
        'change' => '이전 → 이후',
        'ip' => 'IP',
        'approval' => '승인',
    ],

    // 알림톡 발송 로그 (2026-07-13)
    'at_title' => '알림톡 발송 로그',
    'at_subtitle' => '카카오 알림톡 발송·도달 결과. 총 :count건',
    'at_search' => '수신번호 · 템플릿 · 차량번호',
    'at_all_status' => '전체 발송상태',
    'at_all_report' => '전체 도달결과',
    'at_only_attention' => '주의 필요만',
    'at_empty' => '알림톡 발송 로그가 없습니다.',
    'at_ack' => '확인',
    'at_ack_all' => '미도달 전체 확인',
    'at_acked' => '확인됨',
    'at_col' => [
        'time' => '발송시각',
        'template' => '템플릿',
        'phone' => '수신번호',
        'status' => '발송',
        'report' => '도달',
        'vehicle' => '차량',
        'detail' => '내용/오류',
    ],
    'at_status' => [
        'sent' => '접수',
        'failed' => '실패',
        'skipped' => '건너뜀',
    ],
    'at_report' => [
        'delivered' => '도달',
        'undelivered' => '미도달',
        'pending' => '확인중',
    ],
];
