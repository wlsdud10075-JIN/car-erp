<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 💴 급여 항목(귀속월별) — 월정산 v3 1일차 (2026-10-09). */
class PayrollEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_are_the_18_jin_gave_in_order(): void
    {
        $this->assertCount(18, PayrollEntry::ITEMS);
        $this->assertSame('기본급', PayrollEntry::ITEMS[0]);
        $this->assertSame('ERP이용수당', PayrollEntry::ITEMS[17]);
        $this->assertSame(count(PayrollEntry::ITEMS), count(array_unique(PayrollEntry::ITEMS)));
    }

    public function test_replace_keeps_months_apart_and_blank_means_not_entered(): void
    {
        $sm = Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true]);

        $this->assertNull(PayrollEntry::totalFor($sm->id, '2026-10'), '행이 없으면 미입력(null)');

        $total = PayrollEntry::replaceFor($sm->id, '2026-10', [
            ['label' => '기본급', 'amount' => 2_340_000],
            ['label' => '상여', 'amount' => ''],            // 빈칸 = 행 없음
            ['label' => '식대', 'amount' => 200_000],
            ['label' => '전월소급', 'amount' => -50_000],   // 음수 허용
            ['label' => '명절상여', 'amount' => 300_000, 'is_custom' => true],
        ]);
        $this->assertSame(2_790_000, $total);
        $this->assertSame(2_790_000, PayrollEntry::totalFor($sm->id, '2026-10'));
        $this->assertNull(PayrollEntry::totalFor($sm->id, '2026-11'), '다른 달은 그대로 빈칸');

        $rows = PayrollEntry::where('salesman_id', $sm->id)->forMonth('2026-10')->orderBy('sort')->get();
        $this->assertSame(['기본급', '식대', '전월소급', '명절상여'], $rows->pluck('label')->all(), '고정 항목은 ITEMS 순서, 직접 추가 행은 뒤');
        $this->assertTrue($rows->last()->is_custom);
        $this->assertSame(PayrollEntry::CUSTOM_SORT_BASE, $rows->last()->sort);

        // 다시 저장하면 통째로 교체 — 지운 항목이 남지 않는다
        PayrollEntry::replaceFor($sm->id, '2026-10', [['label' => '기본급', 'amount' => 0]]);
        $this->assertSame(0, PayrollEntry::totalFor($sm->id, '2026-10'), '0 은 「없음」을 적은 것 — null 이 아니다');
        $this->assertSame(1, PayrollEntry::where('salesman_id', $sm->id)->count());
    }

    public function test_bad_month_is_rejected(): void
    {
        $sm = Salesman::create(['name' => '검차', 'type' => 'inspector', 'is_active' => true]);
        $this->expectException(\InvalidArgumentException::class);
        PayrollEntry::replaceFor($sm->id, '2026-13', []);
    }
}
