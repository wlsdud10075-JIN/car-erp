<?php

namespace Tests\Feature;

use App\Http\Controllers\CapitalReportController;
use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\CashSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 대표 자금 보고 링크 (jin 2026-07-27, 안건4 3단계).
 * 로그인 없이 서명 링크로 열람 — 회사 재무 전부가 담기므로 서명·만료가 유일한 방어선이다.
 */
class CapitalReportLinkTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(string $date = '2026-07-27'): CashSnapshot
    {
        return CashSnapshot::create([
            'snapshot_date' => $date,
            'balance_krw' => 8_760_397, 'balance_usd' => 16_281.71, 'balance_eur' => 23_169.73,
            'inventory_krw' => 2_350_792_830, 'receivable_krw' => 1_015_201_100, 'payable_krw' => 819_542_180,
            'advance_krw' => 795_294_000, 'auction_deposit_krw' => 14_500_000,
            'fx_usd' => 1495, 'fx_eur' => 1682,
        ]);
    }

    private function link(string $date = '2026-07-27'): string
    {
        return URL::temporarySignedRoute('capital.report',
            now()->addDays(CapitalReportController::LINK_TTL_DAYS), ['date' => $date]);
    }

    public function test_signed_link_opens_without_login(): void
    {
        $this->snapshot();

        $this->get($this->link())
            ->assertOk()
            ->assertSee('자금 보고')
            ->assertSee('지금 정리하면 손에 쥐는 돈');
    }

    /** 서명 없이는 못 연다 — 이게 유일한 방어선이다. */
    public function test_unsigned_url_is_rejected(): void
    {
        $this->snapshot();

        $this->get('/a/capital/2026-07-27')->assertStatus(403);
    }

    public function test_expired_link_is_rejected(): void
    {
        $this->snapshot();
        $url = URL::temporarySignedRoute('capital.report', now()->subMinute(), ['date' => '2026-07-27']);

        $this->get($url)->assertStatus(403);
    }

    /** 그 날짜에 입력이 없으면 직전 스냅샷으로 폴백하고 기준일을 밝힌다(0 을 그리지 않는다). */
    public function test_falls_back_to_previous_snapshot_and_says_so(): void
    {
        $this->snapshot('2026-07-20');

        $this->get($this->link('2026-07-27'))
            ->assertOk()
            ->assertSee('2026-07-20')
            ->assertSee('가장 최근 입력분');
    }

    public function test_no_snapshot_shows_empty_notice(): void
    {
        $this->get($this->link())
            ->assertOk()
            ->assertSee('아직 통장 잔액이 입력되지 않았습니다');
    }

    /** 원금 미설정이어도 0 으로 나누지 않는다. */
    public function test_missing_principal_does_not_break(): void
    {
        $this->snapshot();

        $this->get($this->link())->assertOk()->assertSee('원금 미설정');
    }

    /** 발급은 canViewCapital(super/대표)만. */
    public function test_only_capital_viewer_can_issue_link(): void
    {
        $this->snapshot();
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);

        $this->actingAs($finance);
        Volt::test('admin.dashboard')->call('copyReportLink')->assertStatus(403);
    }

    public function test_admin_gets_working_link(): void
    {
        $this->snapshot();
        AdvanceReceipt::create(['received_date' => '2026-07-01', 'company_name' => '대표이사 가수금', 'amount' => 70_000_000]);
        AuctionDeposit::create(['deposited_date' => '2026-07-01', 'auction_house' => '케이카', 'amount' => 3_000_000]);

        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        $c = Volt::test('admin.dashboard')->call('copyReportLink');
        $url = $c->get('reportLink');
        $this->assertStringContainsString('/a/capital/2026-07-27', $url);

        // 발급된 링크가 실제로 열리고, 펼침 항목에 건별 내역이 들어있다.
        $this->get($url)->assertOk()->assertSee('대표이사 가수금')->assertSee('케이카');
    }

    // ── 🧾 자산 = 자본 + 부채 (대표 2026-11-02 «총 미수금 밑에 총 운영 자금 … 자산 = 자본 + 부채») ──

    /** 맨 위 카드가 총자산을 자산 쪽·부채+자본 쪽으로 펼친다. 비교 대상이 없으면 변동 열 대신 안내 한 줄. */
    public function test_report_opens_with_the_balance_sheet_card(): void
    {
        $this->snapshot();

        $html = $this->get($this->link())->assertOk()->getContent();
        $this->assertStringContainsString('자산 = 자본 + 부채', $html);
        // 총자산 = 현금(8,760,397 + 16,281.71×1495 + 23,169.73×1682) + 선적전재고(구 스냅샷 폴백) + 미수 + 보증금
        $cash = 8_760_397 + (int) round(16_281.71 * 1495 + 23_169.73 * 1682);
        $assets = $cash + 2_350_792_830 + 1_015_201_100 + 14_500_000;
        $this->assertStringContainsString(number_format($assets / 1e8, 2).'억', $html, '총자산');
        $this->assertStringContainsString('부채 합계', $html);
        $this->assertStringContainsString(number_format((819_542_180 + 795_294_000) / 1e8, 2).'억', $html, '부채 = 미지급 + 가수금');
        $this->assertStringContainsString('자본 합계', $html);
        $this->assertStringNotContainsString('환율 재평가', $html, '굳힌 미수가 없는 구 스냅샷은 환차 줄을 안 그린다');
        $this->assertStringContainsString('7일 이상 전 통장 잔액 기록이 생기면', $html);
        $this->assertStringNotContainsString('보고 대비 변동 (', $html, '비교 열 머리말은 없어야 한다');
    }

    /** 7일 이상 전 스냅샷이 있으면 항목별 변동과 「총자산 = 부채 + 내 돈」 변동 한 줄이 붙고, 굳힌 미수가 있으면 환차 줄이 생긴다. */
    public function test_report_shows_the_delta_against_the_previous_report(): void
    {
        $this->snapshot('2026-07-20');
        $now = $this->snapshot('2026-07-27');
        $now->update(['receivable_krw' => 1_000_000_000, 'receivable_frozen_krw' => 980_000_000, 'balance_krw' => 23_960_397]);   // 미수 −1,520만 → 현금 +1,520만

        $html = $this->get($this->link())->assertOk()->getContent();
        $this->assertStringContainsString('7월 20일 보고 대비 변동 (7일)', $html);
        $this->assertStringContainsString('환율 재평가', $html);
        $this->assertStringContainsString('+20,000,000', $html, '오늘 환율 미수 − 굳힌 미수 (1억 미만은 원 단위)');
        $this->assertStringContainsString('class="d dpos">+15,200,000', $html, '현금 변동 +1,520만');
        $this->assertStringContainsString('7월 20일 이후 총자산', $html);
        $this->assertStringContainsString('내 돈', $html);
    }
}
