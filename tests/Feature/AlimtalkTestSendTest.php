<?php

namespace Tests\Feature;

use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use App\Support\AlimtalkTestVars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 기능설정 테스트 발송 — 템플릿 선택형 (jin 2026-08-27).
 *
 * 🔑 이 파일이 막는 것: **테스트 발송만 옛 변수를 들고 남는 일.**
 *   구버전은 `erp_daily_summary` 고정 + 08-20 개편 전 변수(선적전건수·선적후금액·미수합계)를
 *   넘기고 있었다 — 누르면 카드가 깨진 채로 나갔는데, 예외도 실패도 아니라 아무도 몰랐다.
 *   그래서 변수를 손으로 나열하지 않고 **템플릿이 선언한 vars 를 읽게** 했고, 그 계약을 여기서 지킨다.
 */
class AlimtalkTestSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_template_gets_all_of_its_declared_variables(): void
    {
        // 하나라도 비면 그 템플릿은 `#{변수}` 가 치환 안 된 채 나간다.
        foreach (AlimtalkTemplates::TEMPLATES as $code => $t) {
            $declared = $t['vars'] ?? [];
            if ($declared === []) {
                continue;
            }
            $vars = AlimtalkTestVars::for($code);
            foreach ($declared as $name) {
                $this->assertArrayHasKey($name, $vars, "$code: 선언한 변수 '$name' 이 테스트 발송에 없음");
                $this->assertNotSame('', (string) $vars[$name], "$code: '$name' 이 빈 값");
            }
        }
    }

    public function test_no_body_placeholder_survives_substitution(): void
    {
        // 본문에 `#{...}` 가 남으면 카카오가 그대로 찍는다(또는 반려한다).
        foreach (AlimtalkTemplates::TEMPLATES as $code => $t) {
            $body = (string) ($t['body'] ?? '');
            $vars = AlimtalkTestVars::for($code);
            foreach ($vars as $k => $v) {
                if (is_scalar($v)) {
                    $body = str_replace('#{'.$k.'}', (string) $v, $body);
                }
            }
            $this->assertStringNotContainsString('#{', $body, "$code: 치환 안 된 변수가 본문에 남음");
        }
    }

    public function test_aggregate_templates_use_real_data_not_samples(): void
    {
        // 대표가 받는 것과 같은 값이어야 「제대로 나가나」를 확인하는 의미가 있다.
        foreach (['erp_daily_summary', 'erp_weekly_summary', 'erp_monthly_closing',
            'erp_receivable_status', 'erp_capital_weekly'] as $code) {
            $this->assertTrue(AlimtalkTestVars::isRealData($code), "$code 가 샘플로 떨어짐");
        }
    }

    public function test_retired_variable_names_are_gone_from_the_test_path(): void
    {
        // 🔒 08-20 에 없어진 일일요약 변수들. 다시 등장하면 그 코드가 옛 사양을 들고 있다는 뜻이다.
        $retired = ['선적전건수', '선적전금액', '선적후건수', '선적후금액', '미수합계'];
        $vars = AlimtalkTestVars::for('erp_daily_summary');

        foreach ($retired as $old) {
            $this->assertArrayNotHasKey($old, $vars, "폐기된 변수 '$old' 가 테스트 발송에 남아 있음");
        }

        // 주석에는 남아도 된다(왜 없앴는지 설명). **코드로 넘기는 값**만 막는다.
        $source = file_get_contents(app_path('Services/BizmAlimtalkService.php'));
        foreach ($retired as $old) {
            $this->assertStringNotContainsString("'$old' =>", $source, "BizmAlimtalkService 가 폐기 변수 '$old' 를 넘김");
        }
    }

    public function test_dropdown_lists_only_registered_templates(): void
    {
        // 등록 안 된 걸 고를 수 있으면 「보냈는데 아무것도 안 온다」가 된다.
        $config = new AlimtalkConfig(
            'heyman', 'uid', '@prof', 'key', true,
            ['erp_daily_summary' => 'TPL_A'],   // 하나만 등록
            [],
        );

        $options = AlimtalkTestVars::options($config);

        $this->assertSame(['erp_daily_summary'], array_keys($options));
        $this->assertStringContainsString('실데이터', $options['erp_daily_summary']);
    }
}
