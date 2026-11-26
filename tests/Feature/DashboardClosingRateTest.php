<?php

namespace Tests\Feature;

use App\Models\DailyExchangeRate;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 업무 대시보드 「전날 마감환율」 줄 (jin 2026-11-26).
 *  ① 오늘 11-26 이면 「11월 25일 마감환율」 — 송금받을때·매매기준율 둘 다(종류는 FX_CLOSING_KINDS 한 곳).
 *  ② 직전 평일 마감이 없으면 「아직 없음」 표시. 월요일이면 직전 평일 = 금요일(주말은 빠진다).
 *  ③ [받아오기] = 재무 이상만(버튼·서버 둘 다). 받으면 같은 수집 명령이 그 날짜 행을 넣는다.
 */
class DashboardClosingRateTest extends TestCase
{
    use RefreshDatabase;

    /** 외부(네이버)로 안 나가게 — 가짜 응답은 처음 등록한 것이 이기므로 테스트마다 한 번만 건다. */
    private function offline(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'email_verified_at' => now()]);
    }

    private function rate(string $cur, string $date, float $rate, float $base): void
    {
        DailyExchangeRate::create(['currency' => $cur, 'rate_date' => $date, 'rate' => $rate, 'base_rate' => $base, 'source' => 'naver']);
    }

    public function test_shows_previous_business_day_closing_with_both_kinds(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->rate('USD', '2026-11-24', 1329.40, 1342.50);
        $this->rate('USD', '2026-11-25', 1332.40, 1345.50);
        $this->rate('EUR', '2026-11-25', 1462.20, 1476.96);
        $this->rate('USD', '2026-11-26', 1400.00, 1410.00);   // 오늘 = 장중(미확정) — 안 써야 한다
        $this->offline();
        $this->actingAs($this->user('admin'));

        Volt::test('erp.dashboard')->set('roleView', '재무')
            ->assertSeeHtml('data-fx-closing')
            ->assertSee('11월 25일 마감환율')
            ->assertSee('송금받을때')->assertSee('매매기준율')
            ->assertSee('₩1,332.40')->assertSee('₩1,345.50')->assertSee('₩1,476.96')
            ->assertDontSee('₩1,400.00')
            ->assertDontSeeHtml('data-fx-closing-stale');
    }

    public function test_marks_stale_when_previous_business_day_missing_and_skips_weekend(): void
    {
        $this->offline();
        $this->actingAs($this->user('admin'));
        $this->rate('USD', '2026-11-24', 1329.40, 1342.50);

        Carbon::setTestNow('2026-11-26 08:00:00');   // 11-25 마감이 아직 없다(새벽)
        Volt::test('erp.dashboard')->set('roleView', '재무')
            ->assertSee('11월 24일 마감환율')->assertSeeHtml('data-fx-closing-stale');

        Carbon::setTestNow('2026-11-23 10:00:00');   // 월요일 — 직전 평일은 금 11-20
        $this->rate('USD', '2026-11-20', 1331.90, 1345.00);
        DailyExchangeRate::whereDate('rate_date', '>', '2026-11-20')->delete();
        Volt::test('erp.dashboard')->set('roleView', '재무')
            ->assertSee('11월 20일 마감환율')->assertDontSeeHtml('data-fx-closing-stale');
    }

    public function test_fetch_button_is_finance_and_above_only(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->offline();

        $this->actingAs($this->user('user', '재무'));
        Volt::test('erp.dashboard')->set('roleView', '재무')->assertSeeHtml('data-fx-closing-fetch');

        $this->actingAs($this->user('user', '관리'));
        Volt::test('erp.dashboard')->set('roleView', '관리')->assertSeeHtml('data-fx-closing-fetch');

        // 줄은 보이지만 버튼은 없다 — 영업(담당 영업이 연결된 계정)
        $sales = $this->user('user', '영업');
        Salesman::create(['name' => 'S1', 'is_active' => true, 'type' => 'employee', 'user_id' => $sales->id]);
        $this->actingAs($sales->fresh());
        Volt::test('erp.dashboard')->set('roleView', '영업')
            ->assertSeeHtml('data-fx-closing')->assertDontSeeHtml('data-fx-closing-fetch')
            ->call('fetchClosingRates')->assertForbidden();

        // 서버도 막는다 — 수출통관(재무 이상 아님)
        $this->actingAs($this->user('user', '수출통관'));
        Volt::test('erp.dashboard')->call('fetchClosingRates')->assertForbidden();
    }

    public function test_fetch_saves_the_closing_row(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00');
        Http::fake(['*' => Http::response(json_encode(['isSuccess' => true, 'result' => [
            ['localTradedAt' => '2026-11-26T00:00:00+09:00', 'receiveValue' => '1,400.00', 'closePrice' => '1,410.00'],
            ['localTradedAt' => '2026-11-25T00:00:00+09:00', 'receiveValue' => '1,332.40', 'closePrice' => '1,345.50'],
        ]]), 200)]);
        $this->actingAs($this->user('user', '재무'));

        Volt::test('erp.dashboard')->set('roleView', '재무')
            ->assertSeeHtml('data-fx-closing-stale')
            ->call('fetchClosingRates')
            ->assertSee('11월 25일 마감환율')->assertSee('₩1,332.40')
            ->assertDontSeeHtml('data-fx-closing-stale');

        $this->assertFalse(DailyExchangeRate::whereDate('rate_date', '2026-11-26')->exists(), '오늘(장중) 행은 저장하지 않는다');
    }
}
