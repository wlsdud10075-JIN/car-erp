<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 금액칸 부호 처리 정적 가드 (jin 2026-10-07).
 *
 * ①+/- ×1000/÷1000 단축키 폐지 — `-` 를 가로채 「금액(− 가능)」 칸에 음수를 칠 수 없었다.
 * ②음수는 data-money-signed 를 붙인 칸만 — 공용 포매터를 열면 음수 검증 없는 칸으로 오타가 흘러간다(§8 #58).
 * JS 동작이라 기능 테스트로는 못 잡는다(렌더·저장은 정상) — 소스를 직접 본다.
 */
class MoneyInputSignTest extends TestCase
{
    private function appJs(): string
    {
        return file_get_contents(resource_path('js/app.js'));
    }

    public function test_plus_minus_keys_are_not_intercepted_on_money_inputs(): void
    {
        $js = $this->appJs();

        $this->assertDoesNotMatchRegularExpression('/\*\s*1000|\/\s*1000\)/', $js,
            '금액칸 +/- ×1000/÷1000 단축키가 되살아났습니다 — 2026-10-07 폐지.');
        $this->assertDoesNotMatchRegularExpression("/addEventListener\('keydown'[\s\S]{0,200}data-money/", $js,
            '금액칸에 keydown 가로채기가 다시 생겼습니다 — `-` 를 막아 음수 입력이 안 됩니다.');
    }

    public function test_only_signed_inputs_keep_the_minus(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString("hasAttribute('data-money-signed')", $js,
            '포매터가 data-money-signed 로 부호 허용 여부를 가르지 않습니다.');
        $this->assertStringContainsString("const neg = signed && str.trim().startsWith('-');", $js,
            '부호는 data-money-signed 칸에서만 남아야 합니다 — 공용 금액칸은 종전대로 숫자만.');
    }

    public function test_settlement_batch_adjustment_input_accepts_negative(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/erp/settlements/index.blade.php'));

        $this->assertMatchesRegularExpression('/wire:model="newAdjAmount"[^>]*data-money-signed/', $blade,
            '정산 제출 모달 「기타 조정」 금액칸(− 가능)에 data-money-signed 가 없습니다 — 음수를 칠 수 없습니다.');
    }
}
