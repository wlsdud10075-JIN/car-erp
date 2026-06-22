<?php

// i18n — 기능 설정 (admin/settings, super 전용).
return [
    'title' => '기능 설정',
    'subtitle' => '시스템관리자(super) 전용 — 전역 설정',
    'saved' => '저장 완료 — 새로고침하면 사이드바에 반영됩니다.',
    'locale_enabled_flash' => '영어가 활성화되었습니다. 상단바의 한국어 / English 버튼으로 본인 화면을 전환하세요.',
    'locale_disabled_flash' => '영어가 비활성화되었습니다.',

    'brand_section' => '브랜드',
    'brand_label' => '사이드바 브랜드 텍스트',
    'brand_hint' => '사이드바 상단 로고 옆에 표시 (최대 12자). 예: SSANCAR / 산카 ERP',

    'lang_section' => '언어 (다국어)',
    'lang_hint' => '활성화한 언어만 사용자가 상단바에서 선택할 수 있습니다. 끄면 해당 언어 사용자는 한국어로 돌아갑니다.',
    'ko_label' => '한국어',
    'ko_default' => '(기본)',
    'always_on' => '항상 켜짐',
    'en_label' => 'English',
    'en_sub' => '(영어)',

    'alarm_section' => '통관서류 알람',
    'alarm_hint' => '도착(ETA) 10일 전 수출 차량에 "통관서류 작업" 알람이 뜹니다. 켜기 전 `php artisan alarms:scan --dry-run` 으로 대상 건수를 먼저 확인하세요.',
    'alarm_label' => 'ETA 통관서류 알람 사용',
    'alarm_sub' => '(끄면 알람 생성 안 함)',

    'stamp_section' => '도장 · 서명',
    'stamp_hint' => '서류 생성 시 양식의 정해진 위치에 회사 도장/서명을 자동으로 얹습니다. 업로드하지 않으면 양식 기본 이미지가 그대로 쓰입니다. (PNG 투명배경 권장, 최대 2MB)',
    'stamp_signature_label' => '서명',
    'stamp_signature_sub' => '말소계약서에 적용',
    'stamp_uploaded' => '업로드됨',
    'stamp_default' => '양식 기본 사용 중',
    'stamp_upload_btn' => '이미지 선택',
    'stamp_remove_btn' => '기본으로 되돌리기',
    'stamp_removed' => '기본 이미지로 되돌렸습니다.',
    'stamp_invalid' => 'PNG/JPG 이미지만 업로드할 수 있습니다 (최대 2MB).',
];
