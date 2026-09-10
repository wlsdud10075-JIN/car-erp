<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 💾 차량 엑셀 내보내기의 **쿼리·메모리 예산** (2026-09-10 ssancarerp 실사고).
 *
 * 사고: 2026-09-09 `902dcb0` 이 회계실사용 시트 2장을 붙이면서 `$v->finalPayments` ·
 * `$v->receivableHistories` 를 쓰기 시작했는데 **컨트롤러의 eager load 목록에는 안 넣었다**.
 * ssancarerp 4,700대 = 9,400 쿼리 + 그 결과가 전부 메모리에 쌓여 0.65초 만에
 * `Allowed memory size of 134217728 bytes exhausted` 로 500 이 났다.
 *
 * 🚨 **어제 만든 `VehicleExportAuditSheetsTest` 가 이걸 못 잡은 이유** — 그 테스트는
 *    `VehicleExportService::build()` 를 **직접** 부른다. 컨트롤러를 안 타므로 eager load 여부와
 *    무관하게 통과한다. 그래서 여기서는 **라우트를 실제로 호출**해 쿼리를 센다.
 *
 * 🚨 그리고 이 부류는 **작은 회사에서는 영원히 안 드러난다** — heymanerp 311대는 128M 안에 들어간다.
 *    데이터가 많은 회사에서만 터지므로, 대수를 늘렸을 때 쿼리가 늘어나는지를 봐야 한다.
 */
class VehicleExportQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** 돈이 붙은 차량 — 실사 2시트가 관계를 실제로 읽게 만든다(관계가 비면 N+1 이 안 드러난다). */
    private function vehicles(int $count): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        for ($i = 0; $i < $count; $i++) {
            $v = Vehicle::create([
                'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
                'sales_channel' => 'export',
                'currency' => 'EUR',
                'exchange_rate' => 1400,
                'dhl_request' => false,
                'salesman_id' => $s->id,
                'buyer_id' => $buyer->id,
                'purchase_date' => '2026-09-01',
                'purchase_price' => 5_000_000,
                'sale_price' => 9_000_000,
                'sale_date' => '2026-09-05',
            ]);
            FinalPayment::create([
                'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 1000,
                'payment_date' => '2026-09-06', 'confirmed_at' => now(),
            ]);
            ReceivableHistory::create([
                'vehicle_id' => $v->id, 'method' => 'cash', 'amount' => 100,
                'collected_at' => '2026-09-07',
            ]);
        }

        return $buyer;
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * 🔑 **대수를 4배로 늘려도 쿼리 수는 거의 그대로여야 한다.**
     *    관계 하나가 eager load 에서 빠지면 여기서 차이가 대수만큼 벌어진다.
     */
    public function test_query_count_does_not_grow_with_the_number_of_vehicles(): void
    {
        $this->actingAs($this->admin());

        $this->vehicles(3);
        $small = $this->countQueries(fn () => $this->get('/erp/vehicles/export?scope=all')->assertOk());

        $this->vehicles(9);   // 합계 12대 = 4배
        $large = $this->countQueries(fn () => $this->get('/erp/vehicles/export?scope=all')->assertOk());

        // 대수와 무관한 고정 쿼리(권한·설정)만 있으므로 차이는 한 자릿수여야 한다.
        //   ⚠️ 정확히 같기를 요구하지는 않는다 — 캐시 워밍 등으로 1~2개는 흔들린다.
        $this->assertLessThanOrEqual(3, $large - $small,
            "차량이 3대→12대로 늘자 쿼리가 {$small}→{$large} 로 늘었다 — 관계 하나가 eager load 에서 빠졌다");
    }

    /**
     * 🧭 **시트가 읽는 관계는 전부 컨트롤러 eager load 에 있어야 한다** — 새 시트를 붙일 때가 위험하다.
     *    서비스 소스에서 `$v-><관계>` 를 뽑아 Vehicle 의 실제 관계만 걸러 대조한다.
     *    (기능 테스트가 잡아주긴 하지만, 이 정적 검사가 **어디를 고쳐야 하는지**를 바로 알려준다.)
     */
    public function test_every_relation_the_sheets_read_is_eager_loaded(): void
    {
        $service = file_get_contents(base_path('app/Services/VehicleExportService.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/VehicleExportController.php'));

        preg_match_all('/\$v->([a-zA-Z][a-zA-Z0-9_]*)/', $service, $m);

        $missing = [];
        foreach (array_unique($m[1]) as $name) {
            // Vehicle 에 그 이름의 관계 메서드가 있을 때만 대상(컬럼·accessor 는 제외).
            if (! method_exists(Vehicle::class, $name)) {
                continue;
            }
            $ref = new ReflectionMethod(Vehicle::class, $name);
            $ret = $ref->getReturnType();
            if ($ret === null || ! str_contains((string) $ret, 'Relations\\')) {
                continue;
            }
            if (! str_contains($controller, "'".$name."'")) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing,
            '엑셀 시트가 읽는 관계가 eager load 목록에 없다: '.implode(', ', $missing)
            .' — 대량 데이터에서 차량 수만큼 쿼리가 늘어 메모리가 터진다(2026-09-10 ssancarerp).');
    }

    /** 💾 대용량에서 기본 128M 을 넘으므로 이 요청만 상한을 올린다 — php.ini 를 올리면 서버가 위험하다. */
    public function test_export_raises_its_own_memory_limit(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/VehicleExportController.php'));

        $this->assertMatchesRegularExpression("/ini_set\(\s*'memory_limit'/", $src,
            'export 요청이 메모리 상한을 올리지 않는다 — 4,700대에서 기본 128M 을 넘는다');
        $this->assertStringNotContainsString("ini_set('memory_limit', '128M')", $src);
    }
}
