<?php

// 목록 하단 이전/다음 — 이 파일이 없으면 모든 목록에 「« Previous / Next »」 가 영어로 찍힌다(2026-10-13 실측).
// ⚠️ 뷰(vendor/pagination/tailwind.blade.php)가 {!! !!} 로 찍으므로 HTML 엔티티를 그대로 둔다.
return [
    'previous' => '&laquo; 이전',
    'next' => '다음 &raquo;',
];
