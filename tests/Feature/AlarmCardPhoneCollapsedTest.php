<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📱 통관서류 알람 고정 카드 — 폰(sm 미만)에서는 새 알람이 있어도 **접힌 벨**로 시작한다 (jin 2026-10-13 A안).
 *
 * 330px 카드가 390px 화면의 절반 이상을 덮어 모든 화면을 가렸다. 데스크탑은 종전대로 자동으로 펼친다.
 * 실측(Playwright, 390px): open=false · 벨 pill 표시 · 건수 배지 유지 · 탭하면 열림 / 1280px: open=true.
 *
 * ⚠️ 동작은 브라우저 JS(Alpine init) 라 기능 테스트로 못 잡는다 → 정적 검사.
 */
class AlarmCardPhoneCollapsedTest extends TestCase
{
    private const BLADE = 'resources/views/livewire/erp/alarm-center.blade.php';

    public function test_auto_open_is_gated_on_sm_and_up(): void
    {
        $blade = file_get_contents(base_path(self::BLADE));

        $this->assertSame(1, preg_match('/init\(\)\s*\{([\s\S]*?)\n\s*\},/', $blade, $m), 'alarm-center 의 Alpine init() 을 못 찾았다');
        $init = $m[1];

        $this->assertStringContainsString("matchMedia('(min-width: 640px)')", $init, 'sm(640px) 분기가 없다 — 폰에서 카드가 자동으로 펼쳐진다');
        $this->assertMatchesRegularExpression('/this\.open = wide && /', $init, '자동 펼침이 폰 분기와 AND 로 묶여 있지 않다');
    }

    /** 접힌 벨 — 폰에서는 글자(통관서류 알람) 없이 아이콘 + 건수 배지만 (jin «그냥 벨 아이콘으로»). */
    public function test_collapsed_pill_hides_its_label_on_phone(): void
    {
        $blade = file_get_contents(base_path(self::BLADE));

        $this->assertSame(1, preg_match('/<button x-show="!open"[\s\S]*?<\/button>/', $blade, $m), '접힌 벨 버튼을 못 찾았다');
        $pill = $m[0];

        $this->assertMatchesRegularExpression('/<span class="hidden sm:inline">\{\{ __\(\'alarm\.bell_title\'\) \}\}<\/span>/', $pill, '벨 글자가 폰에서 숨겨지지 않는다');
        $this->assertStringContainsString('🔔', $pill);
        $this->assertStringContainsString('{{ $unreadCount }}', $pill, '건수 배지가 사라졌다');
    }
}
