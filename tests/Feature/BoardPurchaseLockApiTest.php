<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\PurchaseRegistrationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board 「바이어 선택」에 실리는 매입 등록 락 (2026-08-10).
 *
 * 왜 필요했나: 매입 락 4겹이 전부 `vehicles/index` 의 Volt `save()` 안에만 있어서,
 * board 가 `PurchaseSyncController` 로 밀어넣는 차는 **아무 락도 안 거치고 다 등록**됐다.
 * 그런데 board 는 이미 `status='won'`(= 낙찰 = 돈이 나간 뒤)에 보내므로 그 시점에 거부해봐야
 * 늦다 — 회사가 소유한 차가 ERP 에 없는 상태가 될 뿐이다. 그래서 **바이어를 고르는 상류**에서
 * 막을 수 있게 판정을 내려준다.
 *
 * 🔒 이 테스트가 지키는 핵심 = **화면 게이트와 API 판정이 갈리지 않는 것.**
 *    갈리면 영업은 board 에서 "가능"을 보고 돈을 쓴 뒤 ERP 에서 막힌다. 사람 눈으로는 못 잡는다.
 */
class BoardPurchaseLockApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function salesman(string $email): Salesman
    {
        return Salesman::create(['name' => 'S'.++$this->counter, 'email' => $email, 'is_active' => true]);
    }

    private function buyer(Salesman $s, ?int $limit = null): Buyer
    {
        return Buyer::create([
            'name' => 'B'.++$this->counter, 'is_active' => true,
            'salesman_id' => $s->id, 'unsecured_limit_krw' => $limit,
        ]);
    }

    /**
     * @param  int  $paidKrw  판매 입금(확정 FP) — 미수율·보증금 한도의 분모/분자를 만든다
     * @param  int  $down  확정 계약금 — 무담보가 묶이는 유일한 대상
     */
    private function vehicle(Buyer $b, int $salePrice, int $paidKrw, int $down = 0): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'BPL'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 20_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-08-02',
            'is_unsecured_down' => true,
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        if ($down > 0) {
            $v->purchaseBalancePayments()->create([
                'amount' => $down, 'type' => 'down',
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    /**
     * 🔒 핵심 가드 — API 가 내려준 `purchase_locked` 가 **차량관리 저장 게이트의 판정과 일치**한다.
     * 두 경로 모두 `PurchaseRegistrationGate` 를 타야만 통과한다.
     */
    public function test_api_verdict_matches_the_save_gate(): void
    {
        $s = $this->salesman('lock@car-erp.test');

        // 미수율 92% — 임계(기본 50%) 초과라 락.
        $blocked = $this->buyer($s);
        $this->vehicle($blocked, 100_000_000, 8_000_000);

        // 미수율 20% — 통과.
        $ok = $this->buyer($s);
        $this->vehicle($ok, 10_000_000, 8_000_000);

        $res = $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'lock@car-erp.test']);
        $res->assertOk();

        $byId = collect($res->json('data'))->keyBy('id');

        foreach ([$blocked, $ok] as $b) {
            $expected = PurchaseRegistrationGate::forBuyer($b->fresh())['locked'];
            $this->assertSame(
                $expected,
                $byId[$b->id]['purchase_locked'],
                "바이어 {$b->name}: API 판정이 저장 게이트와 달라졌다 — board 가 통과시킨 차를 ERP 가 막게 된다"
            );
        }

        $this->assertTrue($byId[$blocked->id]['purchase_locked'], '미수율 초과 바이어는 락이어야 한다');
        $this->assertFalse($byId[$ok->id]['purchase_locked']);
    }

    /** 무담보 한도 바이어는 **금액**으로 판정된다 — 잔액 0이면 락, 남아 있으면 통과. */
    public function test_unsecured_buyer_is_judged_by_amount(): void
    {
        $s = $this->salesman('unsec@car-erp.test');

        // 한도 500만, 계약금 500만이 묶임 → 잔액 0 = 락.
        $exhausted = $this->buyer($s, 5_000_000);
        $this->vehicle($exhausted, 10_000_000, 0, 5_000_000);

        // 한도 500만, 계약금 100만만 묶임 → 잔액 400만 = 통과.
        // (미수율은 100% 라 무담보가 아니었다면 막혔을 바이어다 — 모드 분기가 실제로 갈리는지 본다.)
        $room = $this->buyer($s, 5_000_000);
        $this->vehicle($room, 10_000_000, 0, 1_000_000);

        $byId = collect(
            $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'unsec@car-erp.test'])->json('data')
        )->keyBy('id');

        $this->assertTrue($byId[$exhausted->id]['purchase_locked']);
        $this->assertSame(PurchaseRegistrationGate::MODE_UNSECURED, $byId[$exhausted->id]['purchase_lock']['mode']);
        $this->assertSame(0, $byId[$exhausted->id]['purchase_lock']['unsecured_available_krw']);

        $this->assertFalse($byId[$room->id]['purchase_locked'], '무담보 잔액이 남았으면 미수율이 100% 여도 통과다');
        $this->assertSame(4_000_000, $byId[$room->id]['purchase_lock']['unsecured_available_krw']);
    }

    /** 락 토글 OFF(시스템관리자) — API 도 함께 꺼진다. 화면에만 듣는 킬스위치면 의미가 없다. */
    public function test_lock_toggle_off_disables_the_api_verdict(): void
    {
        $s = $this->salesman('off@car-erp.test');
        $b = $this->buyer($s);
        $this->vehicle($b, 100_000_000, 8_000_000);   // 미수율 92%

        // 락 토글 키는 회사(set)별이다 — 키를 손으로 짜면 다른 회사에서 조용히 안 먹는다.
        Setting::updateOrCreate(
            ['key' => 'lock_purchase_registration_'.Setting::companyTemplateSet()],
            ['value' => '0', 'type' => 'boolean'],
        );

        $this->assertFalse(PurchaseRegistrationGate::enabled(), '토글이 실제로 꺼졌는지 먼저 확인');

        $row = collect(
            $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'off@car-erp.test'])->json('data')
        )->firstWhere('id', $b->id);

        $this->assertFalse($row['purchase_locked']);
        $this->assertSame(PurchaseRegistrationGate::MODE_OFF, $row['purchase_lock']['mode']);
    }

    /** 판정 근거가 없는 바이어(차량 0대·한도 미설정)는 락이 아니다 — 신규 바이어가 막히면 안 된다. */
    public function test_buyer_without_any_basis_is_not_locked(): void
    {
        $s = $this->salesman('fresh@car-erp.test');
        $b = $this->buyer($s);

        $row = collect(
            $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'fresh@car-erp.test'])->json('data')
        )->firstWhere('id', $b->id);

        $this->assertFalse($row['purchase_locked']);
        $this->assertNull($row['purchase_lock']['unpaid_ratio_pct']);
    }

    /** 타 영업 바이어는 애초에 안 나온다 — 락 정보에 미수 금액이 실리므로 스코프가 더 중요해졌다. */
    public function test_scope_still_isolates_other_salesmen(): void
    {
        $mine = $this->salesman('mine@car-erp.test');
        $other = $this->salesman('other@car-erp.test');
        $this->buyer($mine);
        $otherBuyer = $this->buyer($other);

        $ids = collect(
            $this->signedGet('/api/internal/board/buyers', ['salesman_email' => 'mine@car-erp.test'])->json('data')
        )->pluck('id');

        $this->assertNotContains($otherBuyer->id, $ids);
    }

    /**
     * 🧹 락 판정식이 복제되지 않았는지 정적 검사 — 같은 식이 여러 곳에 생기면
     * 하나만 고쳤을 때 화면과 board 숫자가 갈린다(회사이익 공식이 3곳에 복제됐던 그 형태).
     */
    public function test_lock_predicate_lives_in_one_place(): void
    {
        $roots = [base_path('app'), base_path('resources/views')];
        $hits = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), "unsecured_available_krw'] <= 0")) {
                    $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            ['app'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'PurchaseRegistrationGate.php'],
            $hits,
            '매입 락 판정은 PurchaseRegistrationGate 한 곳에만 있어야 한다. 복제하지 말고 그 서비스를 부를 것.'
        );
    }
}
