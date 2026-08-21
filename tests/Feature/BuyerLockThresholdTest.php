<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\LockThresholdResolver;
use App\Services\PurchaseRegistrationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 바이어별 락 필요입금률 (jin 2026-08-21).
 *
 * 우선순위: ①차량별 승인 우회 → ②바이어별 재정의 → ③전역.
 *
 * 🚨 이 테스트의 존재 이유는 **회귀 방지**다. 3사 동시배포라 "컬럼이 전부 NULL 이면
 *    오늘과 완전히 같다"가 깨지면 세 회사의 락이 한꺼번에 달라진다.
 */
class BuyerLockThresholdTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function buyer(array $attrs = []): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(array_merge([
            'name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id,
        ], $attrs));
    }

    private function vehicle(Buyer $b, int $salePrice, int $paidKrw): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'LT'.++$this->n.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $b->salesman_id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000,
            'sale_price' => $salePrice, 'sale_date' => '2026-08-02',
        ]);
        if ($paidKrw > 0) {
            $v->finalPayments()->create([
                'amount' => $paidKrw, 'type' => 'balance', 'exchange_rate' => 1,
                'payment_date' => '2026-08-03', 'confirmed_at' => now(),
            ]);
        }
        $v->refreshCaches();

        return $v->fresh();
    }

    private function setGlobalPct(string $lock, int $pct): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_threshold_'.$lock.'_'.Setting::companyTemplateSet()],
            ['value' => (string) $pct, 'type' => 'integer'],
        );
    }

    // ── ① 회귀 안전망 — 컬럼이 NULL 이면 오늘과 동일 ──────────────

    public function test_null_column_behaves_exactly_like_the_global_setting(): void
    {
        $b = $this->buyer();   // 재정의 없음

        foreach ([0, 30, 50, 60, 100] as $pct) {
            $this->setGlobalPct('shipping_entry', $pct);
            $this->assertSame(
                Setting::lockThreshold('shipping_entry'),
                LockThresholdResolver::threshold($b, 'shipping_entry'),
                "전역 {$pct}% 에서 재정의 없는 바이어가 전역과 달라졌다",
            );
            $this->assertSame(
                Setting::lockRequiredPaidPct('shipping_entry'),
                LockThresholdResolver::requiredPaidPct($b, 'shipping_entry'),
            );
        }
    }

    public function test_null_buyer_falls_back_to_global(): void
    {
        $this->setGlobalPct('shipping_entry', 60);

        $this->assertSame(0.4, round(LockThresholdResolver::threshold(null, 'shipping_entry'), 4));
    }

    // ── ② 재정의가 전역을 이긴다 ────────────────────────────────

    public function test_buyer_override_wins_over_global(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $b = $this->buyer(['lock_shipping_entry_pct' => 80]);

        $this->assertSame(80, LockThresholdResolver::requiredPaidPct($b, 'shipping_entry'));
        $this->assertSame(0.2, round(LockThresholdResolver::threshold($b, 'shipping_entry'), 4));
        $this->assertTrue(LockThresholdResolver::hasOverride($b, 'shipping_entry'));
    }

    public function test_two_locks_are_independent(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $this->setGlobalPct('purchase_registration', 60);
        $b = $this->buyer(['lock_shipping_entry_pct' => 90]);

        $this->assertSame(90, LockThresholdResolver::requiredPaidPct($b, 'shipping_entry'));
        $this->assertSame(60, LockThresholdResolver::requiredPaidPct($b, 'purchase_registration'));
        $this->assertFalse(LockThresholdResolver::hasOverride($b, 'purchase_registration'));
    }

    // ── ③ 0 은 유효값 (NULL 만 미설정) ──────────────────────────

    public function test_zero_means_no_lock_not_unset(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $b = $this->buyer(['lock_shipping_entry_pct' => 0]);

        // 0% 필요 = cutoff 1.0 = 미수 100% 여도 통과
        $this->assertSame(0, LockThresholdResolver::requiredPaidPct($b, 'shipping_entry'));
        $this->assertSame(1.0, LockThresholdResolver::threshold($b, 'shipping_entry'));
        $this->assertTrue(LockThresholdResolver::hasOverride($b, 'shipping_entry'),
            '0 을 미설정으로 취급하면 일부러 풀어준 바이어가 조용히 전역으로 돌아간다');
    }

    // ── ④ 화이트리스트 ─────────────────────────────────────────

    public function test_locks_outside_the_whitelist_throw(): void
    {
        $b = $this->buyer();

        foreach (['bl_issue', 'purchase_payment', 'nonsense'] as $lock) {
            try {
                LockThresholdResolver::threshold($b, $lock);
                $this->fail("'{$lock}' 이 예외 없이 통과했다 — 존재하지 않는 컬럼을 찾아 조용히 전역으로 떨어진다");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($lock, $e->getMessage());
            }
        }
    }

    // ── ⑤ 컬럼을 제한해 로드한 Buyer 에서도 재정의가 살아 있다 ──

    public function test_override_survives_a_column_restricted_buyer(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $b = $this->buyer(['lock_shipping_entry_pct' => 85]);

        // 게이지 배치가 실제로 이렇게 컬럼을 줄여 로드한다 — 그때 재정의가 사라지면 안 된다.
        $lean = Buyer::where('id', $b->id)->get(['id', 'name'])->first();
        $this->assertFalse(array_key_exists('lock_shipping_entry_pct', $lean->getAttributes()),
            '전제: 컬럼이 로드되지 않은 상태');

        $this->assertSame(85, LockThresholdResolver::requiredPaidPct($lean, 'shipping_entry'),
            '컬럼 미로드 시 전역으로 폴백하면 배치 판정이 조용히 틀어진다');
    }

    // ── ⑥ 배치 = 단일 ──────────────────────────────────────────

    public function test_batch_resolver_matches_single_resolution(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $a = $this->buyer(['lock_shipping_entry_pct' => 80]);
        $b = $this->buyer(['lock_shipping_entry_pct' => 0]);
        $c = $this->buyer();   // 전역

        $map = LockThresholdResolver::thresholdsFor([$a->id, $b->id, $c->id], 'shipping_entry');

        foreach ([$a, $b, $c] as $buyer) {
            $this->assertSame(
                LockThresholdResolver::threshold($buyer, 'shipping_entry'),
                $map[$buyer->id],
                "배치와 단일 해석이 갈렸다 (buyer #{$buyer->id})",
            );
        }
    }

    // ── ⑦ 실제 게이트에 반영된다 ───────────────────────────────

    public function test_shipping_entry_override_changes_the_vehicle_gate(): void
    {
        $this->setGlobalPct('shipping_entry', 60);

        // 미수 50% — 전역 60% 기준이면 미달(차단), 40% 기준이면 통과
        $strict = $this->buyer();                                       // 전역 60%
        $loose = $this->buyer(['lock_shipping_entry_pct' => 40]);

        $vs = $this->vehicle($strict, 10_000_000, 5_000_000);
        $vl = $this->vehicle($loose, 10_000_000, 5_000_000);

        $this->assertFalse($vs->isShippingEntryMet(), '전역 60% 에서 미수 50% 는 미달이어야 한다');
        $this->assertTrue($vl->isShippingEntryMet(), '바이어 재정의 40% 면 통과해야 한다');
    }

    public function test_purchase_registration_override_changes_the_gate(): void
    {
        $this->setGlobalPct('purchase_registration', 60);
        Setting::updateOrCreate(
            ['key' => 'lock_purchase_registration_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );

        $strict = $this->buyer();
        $loose = $this->buyer(['lock_purchase_registration_pct' => 40]);
        $this->vehicle($strict, 10_000_000, 5_000_000);   // 미수 50%
        $this->vehicle($loose, 10_000_000, 5_000_000);

        $this->assertTrue(PurchaseRegistrationGate::forBuyer($strict->fresh())['locked'],
            '전역 60% 기준이면 미수 50% 바이어는 매입 등록이 막혀야 한다');
        $this->assertFalse(PurchaseRegistrationGate::forBuyer($loose->fresh())['locked'],
            '바이어 재정의 40% 면 통과해야 한다');
    }

    /**
     * 두 락은 서로 연결돼 있다 — 선적진입 %를 낮추면 그 차가 "선적 진입을 넘긴" 것으로 바뀌어
     * 무담보에 묶였던 계약금이 풀리고, 그만큼 매입 등록 여력이 돌아온다. 의도된 동작이라 박아둔다.
     */
    public function test_shipping_entry_override_feeds_the_unsecured_bucket(): void
    {
        $this->setGlobalPct('shipping_entry', 60);
        $b = $this->buyer(['unsecured_limit_krw' => 5_000_000]);

        $v = $this->vehicle($b, 10_000_000, 5_000_000);   // 미수 50%
        $v->update(['is_unsecured_down' => true]);
        $v->purchaseBalancePayments()->create([
            'amount' => 3_000_000, 'type' => 'down',
            'payment_date' => '2026-08-03', 'confirmed_at' => now(),
        ]);

        $tight = $b->fresh()->receivableGauge();
        $this->assertSame(3_000_000, $tight['unsecured_used_krw'],
            '선적 진입 미달이면 계약금이 무담보에 묶여 있어야 한다');

        // 그 바이어만 40% 로 풀어주면 선적 진입을 넘긴 것이 되어 묶임이 해제된다.
        $b->update(['lock_shipping_entry_pct' => 40]);
        $loose = $b->fresh()->receivableGauge();
        $this->assertSame(0, $loose['unsecured_used_krw'],
            '선적 진입을 넘기면 무담보 묶임이 풀려야 한다');
    }

    // ── ⑧ 정적 가드 — resolver 밖에서 직접 부르는 곳이 없어야 한다 ──

    public function test_no_code_bypasses_the_resolver(): void
    {
        $offenders = [];
        foreach ([base_path('app'), base_path('resources/views/livewire')] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains($path, 'LockThresholdResolver.php')) {
                    continue;   // 해석기 자신은 예외
                }
                foreach (file($file->getPathname()) as $i => $line) {
                    if (preg_match("/Setting::lock(Threshold|RequiredPaidPct)\(\s*'(shipping_entry|purchase_registration)'/", $line)) {
                        $offenders[] = basename($path).':'.($i + 1).' — '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "바이어별 락 대상을 Setting 에서 직접 읽으면 바이어 재정의가 **조용히** 무시된다.\n".
            "화면은 정상 렌더되고 예외도 로그도 안 나므로 기능 테스트로는 못 잡는다.\n".
            "→ LockThresholdResolver::threshold(\$buyer, ...) 를 쓸 것.\n".
            implode("\n", $offenders));
    }
}
