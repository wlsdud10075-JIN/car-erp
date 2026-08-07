<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 잔금 행 원화 환산칸이 **타이핑 중에** 갱신되는가 (jin 2026-08-07).
 *
 * 금액·환율은 `wire:model`(deferred)이라 blur 해야 서버로 간다. 환산액을 서버 렌더에만
 * 맡기면 타이핑 중엔 안 변하고, blur 후 차량 패널(8천 줄)이 통째로 재렌더되며 몇 초 걸린다.
 * 사용자에게는 **"환산 금액이 엄청 느리다"** 로 보인다 — 성능이 아니라 배선이다(CLAUDE.md #15).
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 서버 렌더 결과는 어느 쪽이든 정상이다.
 *    속성 하나만 지워져도 조용히 옛 동작으로 돌아가므로 정적으로 묶어둔다.
 */
class FinalPaymentKrwLiveCalcTest extends TestCase
{
    private function panel(): string
    {
        return file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));
    }

    private function appJs(): string
    {
        return file_get_contents(resource_path('js/app.js'));
    }

    public function test_final_payment_row_carries_the_hooks_client_calc_needs(): void
    {
        $blade = $this->panel();

        foreach (['data-fp-row', 'data-fp-rate', 'data-fp-amount', 'data-fp-krw'] as $attr) {
            $this->assertStringContainsString(
                $attr,
                $blade,
                "잔금 행에서 {$attr} 가 사라졌다 — 원화 환산이 다시 서버 왕복(blur 후 수 초)으로 돌아간다"
            );
        }
    }

    public function test_app_js_recalculates_on_input(): void
    {
        $js = $this->appJs();

        $this->assertStringContainsString('data-fp-krw', $js, 'app.js 에 환산칸 갱신 핸들러가 없다');
        $this->assertMatchesRegularExpression(
            "/addEventListener\(\s*'input'/",
            $js,
            '입력 이벤트 위임이 없으면 타이핑 중 갱신이 안 된다'
        );
        $this->assertStringContainsString(
            'data-fp-amount], [data-fp-rate]',
            $js,
            '금액·환율 두 칸 모두를 듣지 않으면 한쪽만 고쳤을 때 환산이 안 맞는다'
        );
    }

    /**
     * 문서 위임이어야 한다 — 행마다 리스너를 달면 wire:navigate 복원·morph 후 죽는다(SKILLS §8 #21).
     * 그때 증상이 "어떤 행은 되고 어떤 행은 안 됨"이라 원인 찾기가 오래 걸린다.
     */
    public function test_handler_is_document_delegated(): void
    {
        $js = $this->appJs();
        $pos = strpos($js, 'recalcFinalPaymentKrw');
        $this->assertNotFalse($pos);

        $tail = substr($js, $pos);
        $this->assertMatchesRegularExpression(
            "/document\.addEventListener\(\s*'input'/",
            $tail,
            '행 단위 바인딩이면 morph 후 죽는다 — document 위임을 유지할 것'
        );
    }

    /** 서버 렌더값도 남아 있어야 한다 — 패널을 처음 열었을 때(입력 전) 환산액이 보여야 하므로. */
    public function test_server_still_renders_the_initial_value(): void
    {
        $this->assertStringContainsString(
            '$rowAmt * $rowRate',
            $this->panel(),
            '초기 표시용 서버 계산이 사라지면 패널을 열자마자 환산칸이 비어 보인다'
        );
    }
}
