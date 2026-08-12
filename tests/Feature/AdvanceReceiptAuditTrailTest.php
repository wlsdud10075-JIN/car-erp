<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔍 예치·가수금 감사 추적 (jin 2026-08-12 — "로그기록도 없고 알수가 없는거같아").
 *
 * 이 원장은 **청산가치를 억 단위로 움직인다**(ssancarerp 실측 가수금 35.7억). 그런데 종전엔
 * 생성·삭제·성격변경·상환 어느 것도 감사로그에 안 남았다 — 15억짜리 행이 흔적 없이 사라질 수 있었다.
 *
 * ⚠️ 이 부류는 화면이 정상 렌더돼 기능 테스트로는 안 드러난다. 로그 행의 **존재**를 직접 단언한다.
 */
class AdvanceReceiptAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function logsFor(string $class, ?int $id = null)
    {
        return AuditLog::where('auditable_type', $class)
            ->when($id, fn ($q) => $q->where('auditable_id', $id))
            ->get();
    }

    /** 🚨 상환완료가 감사로그에 남는다 — jin 이 직접 제보한 구멍. */
    public function test_marking_repaid_is_audited_with_the_date_and_the_person(): void
    {
        $user = $this->actor();
        $this->actingAs($user);

        $row = AdvanceReceipt::create([
            'received_date' => '2026-08-01',
            'company_name' => '사모사채',
            'amount' => 1_500_000_000,
            'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);

        Volt::test('erp.deposits.index')->call('markRepaid', $row->id);

        $today = now()->toDateString();
        $this->assertSame($today, $row->fresh()->repaid_at->toDateString());
        $this->assertSame($user->id, $row->fresh()->repaid_by, '상환 처리자가 안 찍혔다');

        $log = $this->logsFor(AdvanceReceipt::class, $row->id)->firstWhere('column_name', 'repaid_at');
        $this->assertNotNull($log, '상환 처리가 감사로그에 안 남았다');
        $this->assertSame($today, $log->new_value);
        $this->assertSame($user->id, $log->user_id, '누가 눌렀는지가 로그에 없다');
    }

    /** 무르기도 남는다 — 되돌린 사실이 안 남으면 "갚았다가 안 갚은 것"이 사라진다. */
    public function test_undo_is_audited_too(): void
    {
        $this->actingAs($this->actor());

        $row = AdvanceReceipt::create([
            'received_date' => '2026-08-01', 'company_name' => '무역금융',
            'amount' => 500_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);

        $c = Volt::test('erp.deposits.index');
        $c->call('markRepaid', $row->id);
        $c->call('undoRepaid', $row->id);

        $logs = $this->logsFor(AdvanceReceipt::class, $row->id)->where('column_name', 'repaid_at');
        $this->assertCount(2, $logs, '상환·무르기 두 줄이 다 남아야 한다');
        $this->assertNull($logs->last()->new_value);
        $this->assertNull($row->fresh()->repaid_at);
    }

    /**
     * 🚨 성격 변경은 **청산가치와 원금을 동시에** 억 단위로 흔든다(liability↔equity).
     * 종전엔 완전히 무기록이었다.
     */
    public function test_nature_change_is_audited(): void
    {
        $this->actingAs($this->actor());

        $row = AdvanceReceipt::create([
            'received_date' => '2026-08-01', 'company_name' => '대표이사 가수금',
            'amount' => 300_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);

        Volt::test('erp.deposits.index')->call('setNature', $row->id, AdvanceReceipt::NATURE_EQUITY);

        $log = $this->logsFor(AdvanceReceipt::class, $row->id)->firstWhere('column_name', 'nature');
        $this->assertNotNull($log, '성격 변경이 감사로그에 안 남았다');
        $this->assertSame('liability', $log->old_value);
        $this->assertSame('equity', $log->new_value);
    }

    /** 삭제는 **금액을 로그에 박고** 지운다 — 지운 뒤엔 얼마였는지 알 길이 없다. */
    public function test_delete_records_the_amount_before_removing_the_row(): void
    {
        $this->actingAs($this->actor());

        $row = AdvanceReceipt::create([
            'received_date' => '2026-08-01', 'company_name' => '오입력',
            'amount' => 270_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);

        Volt::test('erp.deposits.index')->call('remove', $row->id);

        $logs = $this->logsFor(AdvanceReceipt::class, $row->id);
        $this->assertSame('270000000', $logs->firstWhere('column_name', 'amount')?->old_value,
            '삭제 전 금액이 로그에 안 박혔다');
        $this->assertNotNull($logs->firstWhere('action', 'deleted'));
    }

    /** 생성도 남는다 — 없던 35억 부채가 갑자기 생겨도 출처를 알 수 있어야 한다. */
    public function test_create_is_audited_for_both_tabs(): void
    {
        $this->actingAs($this->actor());

        Volt::test('erp.deposits.index')
            ->set('tab', 'advance')->set('party', '사모사채')->set('amount', '1,500,000,000')
            ->call('add');
        $this->assertNotNull($this->logsFor(AdvanceReceipt::class)->firstWhere('action', 'created'));

        Volt::test('erp.deposits.index')
            ->call('setTab', 'auction')
            ->set('party', '현대오토', 'amount')->set('amount', '10,000,000')
            ->call('add');
        $this->assertNotNull($this->logsFor(AuctionDeposit::class)->firstWhere('action', 'created'),
            '경매보증금도 청산가치에 들어가는데 기록이 없다');
    }

    /**
     * 👀 누른 행이 눈앞에서 사라지지 않는다 — 기본이 「미상환만 보기」라 상환완료를 누르는 순간
     * 행이 증발해 **날짜가 찍혔는지 확인할 방법이 없었다**(jin 실제 제보).
     */
    public function test_repaid_row_stays_visible_right_after_the_click(): void
    {
        $this->actingAs($this->actor());

        $row = AdvanceReceipt::create([
            'received_date' => '2026-08-01', 'company_name' => '신한 동행 중소기업',
            'amount' => 270_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);

        Volt::test('erp.deposits.index')
            ->assertSet('showRepaid', false)
            ->call('markRepaid', $row->id)
            ->assertSet('showRepaid', true)
            ->assertSee('신한 동행 중소기업')
            ->assertSee(now()->format('Y-m-d'));
    }

    /** 🇰🇷 감사 화면에 영문이 새지 않는다 — 모델·컬럼·값 전부 한글 사전이 있어야 한다. */
    public function test_labels_are_korean_in_the_audit_screen(): void
    {
        $this->assertSame('가수금', ColumnLabel::model(AdvanceReceipt::class));
        $this->assertSame('경매 보증금', ColumnLabel::model(AuctionDeposit::class));
        $this->assertSame('상환일', ColumnLabel::column(AdvanceReceipt::class, 'repaid_at'));
        $this->assertSame('성격', ColumnLabel::column(AdvanceReceipt::class, 'nature'));
        $this->assertSame('금액', ColumnLabel::column(AuctionDeposit::class, 'amount'));
        $this->assertSame('갚아야 할 돈', ColumnLabel::value(AdvanceReceipt::class, 'nature', 'liability'));
        $this->assertSame('대표 자산', ColumnLabel::value(AdvanceReceipt::class, 'nature', 'equity'));
    }
}
