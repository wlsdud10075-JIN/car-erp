<?php

namespace Tests\Feature;

use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Tests\TestCase;

/**
 * 🚨 알림톡 코드는 **수신자 표 셋 중 정확히 하나**에 있어야 한다.
 *
 * 어디에도 없으면 그 알림은 **영영 안 나간다**:
 *   · `isBroadcast()` = false → 알림톡 안내 화면에 역할 체크박스가 **안 뜬다**
 *   · `saveRoles()` 가 non-broadcast 를 hard-return → **설정으로 켤 방법도 없다**
 *   · `selectedRoles()` = [] → `forBroadcast()` = [] → 스케줄이 "수신자 없음 — skip"
 *
 * 예외도 로그도 없다. 화면은 정상이고 cron 도 매일 정상 종료한다 — SKILLS §8 #38 의 그 형태
 * (값을 쓰는 쪽만 남고 소비자가 조용히 0건).
 *
 * 2026-08-24 실사고: 채권현황(`erp_receivable_status`)이 BizM 승인·tmplId 입력까지 끝났는데
 * 이 줄이 없어 heymanerp 발송 0건이었다. 운영 실측 = 설정행 없음 · 수신자 0명 ·
 * alimtalk_logs 에 스케줄 발송 기록 0건(수동 테스트 1건뿐).
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 커맨드는 "수신자 없음" 을 **성공(SUCCESS)** 으로 끝낸다.
 */
class AlimtalkRecipientTableTest extends TestCase
{
    public function test_every_template_resolves_its_recipients_somewhere(): void
    {
        $covered = array_merge(
            array_keys(AlimtalkRecipients::DEFAULT_ROLES),
            array_keys(AlimtalkRecipients::TARGETED_LABELS),
            AlimtalkRecipients::TIME_RULE_CODES,
        );

        $orphans = array_values(array_diff(array_keys(AlimtalkTemplates::TEMPLATES), $covered));

        $this->assertSame([], $orphans,
            "아래 알림톡은 수신자를 해석할 방법이 없어 **영영 발송되지 않는다**.\n"
            ."셋 중 하나에 넣을 것:\n"
            ."  · DEFAULT_ROLES   — 역할 선택형(안내 화면 체크박스). 빈 배열도 가능(기본 아무도 안 받음)\n"
            ."  · TARGETED_LABELS — 자동 대상(본인·기안자·딜러 등). 화면엔 고정 라벨만\n"
            ."  · TIME_RULE_CODES — 시각 규칙 라우팅\n  ".implode("\n  ", $orphans));
    }

    /** 두 표에 겹치면 화면이 체크박스와 고정 라벨을 동시에 그려 «무엇이 실제인지» 가 갈린다. */
    public function test_no_template_appears_in_two_recipient_tables(): void
    {
        $covered = array_merge(
            array_keys(AlimtalkRecipients::DEFAULT_ROLES),
            array_keys(AlimtalkRecipients::TARGETED_LABELS),
            AlimtalkRecipients::TIME_RULE_CODES,
        );

        $dupes = array_keys(array_filter(array_count_values($covered), fn ($n) => $n > 1));

        $this->assertSame([], $dupes, '수신자 표 중복: '.implode(', ', $dupes));
    }

    /** 표에만 있고 템플릿이 없는 코드 = 폐기 잔재. 안내 화면에 «절대 안 오는 알림» 이 남는다. */
    public function test_recipient_tables_have_no_dangling_codes(): void
    {
        $codes = array_keys(AlimtalkTemplates::TEMPLATES);
        foreach ([
            'DEFAULT_ROLES' => array_keys(AlimtalkRecipients::DEFAULT_ROLES),
            'TARGETED_LABELS' => array_keys(AlimtalkRecipients::TARGETED_LABELS),
            'TIME_RULE_CODES' => AlimtalkRecipients::TIME_RULE_CODES,
        ] as $table => $listed) {
            $dangling = array_values(array_diff($listed, $codes));
            $this->assertSame([], $dangling,
                "{$table} 에 템플릿 없는 코드가 남아 있다: ".implode(', ', $dangling));
        }
    }
}
