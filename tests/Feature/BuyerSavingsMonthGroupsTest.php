<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📅 바이어 적립금 거래·사용 내역 — 전체 + 월별 접기 (jin 2026-11-17).
 *   제보: ssancarerp GOLDEN AUTO 적립금을 정리하다 「내용이 잘려서 안 보인다」 — 실측 54건 중 오래된 4건이 최근 50건 제한에 걸려
 *   화면에 아예 없었고, 메모(최장 82자)는 PC 에서 120px 한 줄로 잘렸다.
 *   지키는 것: ① 50건 넘어도 전부 ② 달마다 묶음(최근 달만 펼침) ③ 메모는 자르지 않는다.
 */
class BuyerSavingsMonthGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_rows_are_listed_grouped_by_month_and_notes_are_not_truncated(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        // 사용 내역(보라 표)은 현금 원장을 쓰는 회사의 「현금」 탭에 있다
        Setting::updateOrCreate(['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        $b = Buyer::create(['name' => 'GOLDEN AUTO', 'is_active' => true]);
        $long = str_repeat('긴 메모 내용 ', 12);
        $balance = 0;
        foreach (range(1, 55) as $i) {
            $month = $i <= 30 ? '2026-08' : '2026-11';
            $type = $i % 3 === 0 ? 'USED' : 'EARNED';
            $amt = $type === 'USED' ? -10 : 30;
            $balance += $amt;
            $row = SavingsStatus::create(['buyer_id' => $b->id, 'currency' => 'EUR', 'transaction_type' => $type, 'savings' => $amt, 'balance' => $balance, 'note' => $i === 1 ? $long : "줄 {$i}"]);
            $row->forceFill(['created_at' => "{$month}-".str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT).' 10:00:00'])->saveQuietly();
        }

        $c = Volt::test('erp.buyers.index')->call('openEdit', $b->id);

        $this->assertCount(55, $c->get('txnList'), '50건 제한 없음');
        $this->assertCount(18, $c->get('savingsUseList'), '사용 내역도 전부');
        $html = $c->html();
        $this->assertSame(55, substr_count($html, 'data-savings-note'), '메모가 있는 모든 행이 그려진다');
        $this->assertMatchesRegularExpression('/x-data="\{ open: true \}"[^>]*data-savings-month="2026-11"/', $html, '최근 달은 펼침');
        $this->assertMatchesRegularExpression('/x-data="\{ open: false \}"[^>]*data-savings-month="2026-08"/', $html, '지난 달은 접힘');
        $this->assertStringContainsString('data-savings-use-month="2026-08"', $html);
        $this->assertStringContainsString(trim($long), $html, '긴 메모가 통째로 나온다');
        $this->assertStringNotContainsString('max-w-[120px] truncate', $html, '한 줄 자르기 없음');
    }
}
