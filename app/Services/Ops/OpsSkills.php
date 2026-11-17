<?php

namespace App\Services\Ops;

/**
 * 🧰 관제센터 SKILLS 칸 — 세션별 스킬 목록 (jin 2026-11-17 «ssancar.com·ERP·board 에서 만든 스킬 종류가 표시만 되면 좋겠다»).
 *
 * 표시 전용. 관제센터는 ssancarerp 서버에서 돌고 스킬 파일은 jin PC 의 각 레포(.claude/skills · .claude/commands)에만 있어
 * 서버가 직접 읽을 수 없다 ⇒ **목록을 여기 한 곳에 둔다.** 스킬을 새로 만들면 이 배열에 한 줄 추가한다.
 *   출처: car-erp `.claude/skills/*` · `.claude/commands/회의.md` / board `.claude/skills/*` / ssancar `.claude/skills/*` / 공통 = 사용자 전역·플러그인.
 */
class OpsSkills
{
    /** @var array<string, array{label: string, skills: list<array{0: string, 1: string}>}> */
    public const GROUPS = [
        'erp' => ['label' => 'ERP', 'skills' => [
            ['적재양식 import', 'xlsx → 차량 일괄 등록'],
            ['컨사이니 import', '바이어·컨사이니 일괄 등록'],
            ['씬 점검', '주간 화면 회귀 점검 · 토 10:00 자동'],
            ['메모리·문서 정리', '주간 메모리 정리 · /memory-cleanup'],
            ['라운드테이블 회의', '6부서 검토 · /회의'],
        ]],
        'board' => ['label' => 'board', 'skills' => [
            ['씬 점검', 'board 주간 화면 회귀 점검 · 토 ERP 점검 뒤'],
        ]],
        'ssancar' => ['label' => 'ssancar.com', 'skills' => [
            ['포털 화면 규약', 'My SSANCAR 포털 CSS·화면 작업 규칙'],
            ['동기화·배포', '서버↔GitHub↔dev 미러 확인·흡수·배포'],
        ]],
        'common' => ['label' => '공통', 'skills' => [
            ['교차 검증', 'Codex·Gemini·Claude 답 비교'],
            ['심층 인터뷰', '모호한 요청을 질문으로 요구사항 정리'],
        ]],
    ];
}
