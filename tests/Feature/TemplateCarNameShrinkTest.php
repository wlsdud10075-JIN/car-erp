<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📄 양식 차명칸 「셀에 맞춤」 (jin 2026-11-17 — 통관 SET 등록증 E5 · 말소증 E7 차명이 길면 잘렸다).
 *
 * 양식 xlsx 를 다시 만들거나(생성 스크립트) 교체하면 이 서식이 조용히 빠진다 — 서류는 정상 생성되고 긴 차명만 잘린다.
 * 판정은 고친 스크립트의 --verify 를 그대로 쓴다(zip 안 styles.xml 기준 — PhpSpreadsheet 읽기는 "false" 문자열을 참으로 읽어 오판한다).
 */
class TemplateCarNameShrinkTest extends TestCase
{
    public function test_car_name_cells_shrink_to_fit_in_every_set(): void
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('scripts/fix-template-car-name-shrink.php')).' --verify', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
    }
}
