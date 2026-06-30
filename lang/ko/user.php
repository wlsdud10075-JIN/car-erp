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
        'user' => '일반사용자 (user)',
    ],

    'saved' => '사용자 정보가 저장됐습니다.',
    'deleted' => '사용자가 삭제됐습니다.',
    'self_delete' => '본인 계정은 삭제할 수 없습니다.',
    'no_super' => '시스템관리자 권한은 부여할 수 없습니다.',
];
