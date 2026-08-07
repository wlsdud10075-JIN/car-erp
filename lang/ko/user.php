<?php

// i18n — 사용자 관리 (admin/users). permission=nav.permission, role=domain.role, type=salesman.type 재사용.
return [
    'title' => '사용자 관리',
    'total' => '총 :count명',
    'create_btn' => '사용자 추가',
    'search_ph' => '이름 · 이메일',
    'all_perm' => '전체 권한',
    'me' => '(나)',
    'no_login' => '없음',
    'phone_missing' => '미입력',
    'phone_missing_hint' => '전화번호가 없으면 이 사용자에게는 알림톡이 발송되지 않습니다(로그도 남지 않음).',
    'empty' => '사용자가 없습니다.',
    'delete_confirm' => ':name 사용자를 삭제하시겠습니까?',
    'edit_title' => '사용자 수정',
    'create_title' => '사용자 추가',

    'col' => [
        'name' => '이름',
        'perm' => '권한',
        'role' => '역할',
        'last_login' => '마지막 로그인',
    ],
    'field' => [
        'name' => '이름',
        'name_ph' => '홍길동',
        'email_ph' => 'user@car-erp.test',
        'phone' => '전화번호',
        'password' => '비밀번호',
        'password_edit_note' => '(빈 칸 = 변경 안 함)',
        'password_ph' => '8자 이상',
        'sec_perm' => '권한 설정',
        'perm' => '권한',
        'role' => '역할',
        'role_note' => '역할에 따라 접근 가능한 메뉴가 달라집니다.',
        'settlement_type' => '정산 분류',
        'type_select' => '— 선택 —',
        'type_note' => '거래완료 시 자동 생성되는 정산 방식 결정 — 누락 방지를 위해 명시 선택 필수',
        'manager' => '담당 [관리] (다수 선택 가능)',
        'manager_none' => '등록된 [관리] 없음',
        'manager_note' => '이 영업을 담당할 [관리]를 여러 명 체크 가능 — 선택된 [관리]는 모두 이 영업의 차량/바이어를 조회·편집. 미선택 시 어떤 [관리] 솔팅에도 안 잡힘',
    ],

    // 권한 select (코드 suffix 포함)
    'perm_opt' => [
        'super' => '시스템관리자 (super)',
        'admin' => '최고관리자 (admin)',
        'manager' => '업무관리자 (manager)',
        'user' => '일반사용자 (user)',
    ],

    // 휴가 대리 위임 (jin 2026-08-07) — 자리를 비우는 동안 담당 영업을 통째로 넘긴다.
    'delegation' => [
        'my_title' => '내 담당 영업 위임',
        'my_note' => '휴가 등으로 자리를 비울 때, 내 담당 영업 전원을 한 번에 넘깁니다. 복귀 예정일이 지나면 자동으로 풀립니다.',
        'to_ph' => '— 맡길 사람 —',
        'until_ph' => '복귀 예정일',
        'start' => '위임 시작',
        'stop' => '위임 종료',
        'on' => '위임 중 · :date 까지',
        'handed_to' => ':name 님이 내 담당 영업 :count명을 함께 봅니다.',
        'team' => '담당 영업 — :names',
        'need_end_date' => '복귀 예정일을 입력해야 위임을 시작할 수 있습니다.',
        'past_end_date' => '복귀 예정일이 이미 지났습니다.',
        'activated' => ':name 님에게 담당 영업을 위임했습니다.',
        'deactivated' => '위임을 종료했습니다.',
    ],

    'saved' => '사용자 정보가 저장됐습니다.',
    'deleted' => '사용자가 삭제됐습니다.',
    'self_delete' => '본인 계정은 삭제할 수 없습니다.',
    'no_super' => '시스템관리자 권한은 부여할 수 없습니다.',
];
