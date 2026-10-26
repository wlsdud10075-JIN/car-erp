<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 감사로그 이전→이후 값 (jin 2026-10-26 «내용이 잘려서 보이는데 다 나오게 하되, UX 를 해칠 길이면 … 으로 + 호버»).
 * 160자까지는 그대로(줄바꿈), 넘으면 … 으로 자르고 title 에 전문. 잘리든 아니든 title 은 늘 있다.
 */
class AuditLogValueDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $old, string $new): void
    {
        $v = Vehicle::create(['vehicle_number' => 'LOG-1', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false]);
        AuditLog::create(['user_id' => auth()->id(), 'auditable_type' => Vehicle::class, 'auditable_id' => $v->id, 'action' => 'updated', 'column_name' => 'memo', 'old_value' => $old, 'new_value' => $new]);
    }

    public function test_medium_values_are_shown_in_full_and_long_ones_are_cut_with_a_hover(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super']));
        $medium = str_repeat('가나다라마바사 ', 10);           // 80자 — 종전 40자 한도에선 잘렸다
        $long = 'L'.str_repeat('0123456789', 25);             // 251자 — 160자 넘음
        $this->log($medium, $long);

        $html = Volt::test('admin.audit-logs.index')->html();

        $this->assertStringContainsString(trim($medium), $html, '80자는 통째로 보인다');
        $this->assertStringContainsString(e($long), $html, '긴 값도 title 에 전문이 있다');
        $this->assertStringContainsString('L'.str_repeat('0123456789', 15).'012345678...', $html, '160자에서 …');
        $this->assertStringNotContainsString('>'.$long.'<', $html, '본문엔 전문이 그대로 안 나온다');
        $this->assertMatchesRegularExpression('/<span class="text-emerald-600" title="L0123/', $html);
    }
}
