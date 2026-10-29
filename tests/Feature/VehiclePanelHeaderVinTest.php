<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🪪 **차량 편집 패널 헤더 — 차량번호 옆에 차대번호** (jin 2026-10-29).
 *
 * «차량번호 바로 옆에 나란히 차대번호가 나오게. NICE 조회돼서 있으면 그 값, 없으면 「차대번호 없음」.
 *  하얀 글씨라 잘 안 보이는데 굵고 선명하게.»
 *
 * - 값은 NICE 등록 칸 `nice_reg_vin` 하나다(기본정보 탭에서 손으로 넣은 값도 같은 칸).
 * - 렌더 가드: 두 문자열이 **같은 헤더 문단 안에** 있는지를 본다(페이지 어딘가에 있는지가 아니라 — §8 #107).
 */
class VehiclePanelHeaderVinTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create([
            'permission' => 'super', 'role' => '관리', 'email_verified_at' => now(),
        ]));
    }

    private function headerLine(string $html): string
    {
        // 헤더 문단 = 차량번호를 담은 <p …font-mono…> 한 블록
        $this->assertSame(1, preg_match('/<p[^>]*font-mono[^>]*>([\s\S]*?)<\/p>/u', $html, $m), '헤더 문단을 못 찾았다');

        return $m[1];
    }

    public function test_vin_is_shown_right_next_to_the_plate(): void
    {
        $this->login();
        $v = Vehicle::create(['vehicle_number' => '12가3456', 'nice_reg_vin' => 'kmhxx00xxxx123456']);

        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
        $line = $this->headerLine($html);

        $this->assertStringContainsString('12가3456', $line);
        $this->assertStringContainsString('KMHXX00XXXX123456', $line, '차대번호는 대문자로 차량번호 옆에');
        $this->assertStringNotContainsString(__('vehicle.panel.vin_none'), $line);
        $this->assertMatchesRegularExpression('/<p[^>]*font-bold[^>]*text-gray-900/u', $html, '굵고 진한 글씨');
    }

    public function test_missing_vin_says_so(): void
    {
        $this->login();
        $v = Vehicle::create(['vehicle_number' => '12가3456', 'nice_reg_vin' => null]);

        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
        $line = $this->headerLine($html);

        $this->assertStringContainsString('12가3456', $line);
        $this->assertStringContainsString(__('vehicle.panel.vin_none'), $line);
    }
}
