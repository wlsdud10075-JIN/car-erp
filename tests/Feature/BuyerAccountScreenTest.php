<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\BuyerCashReceipt;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 바이어 정산현황 4단계 — 화면 · 묶음별 · 엑셀. 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚫 「정산」은 이 ERP 에서 **담당자 지급**을 뜻한다. 이 화면은 바이어와의 대금이다 —
 *    코드·영문은 account 를 쓰고, 화면 이름은 항상 「바이어 정산현황」 전체로 쓴다.
 */
class BuyerAccountScreenTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    private function vehicle(Buyer $buyer, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.str_pad((string) (1000 + ++$this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => 'EUR',
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $buyer->salesman_id,
            'buyer_id' => $buyer->id,
            'sale_price' => 10000,
            'sale_date' => now()->toDateString(),
        ], $attrs));
    }

    private function enable(): void
    {
        Setting::updateOrCreate(
            ['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()],
            ['value' => '1', 'type' => 'boolean'],
        );
    }

    // ── 게이트 ───────────────────────────────────────────────────

    /** 토글이 꺼진 회사엔 화면 자체가 없다 — 메뉴만 숨기면 주소를 직접 치면 들어온다(§8 #26). */
    public function test_screen_is_404_while_the_toggle_is_off(): void
    {
        $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.index'))
            ->assertNotFound();
    }

    public function test_screen_opens_when_enabled(): void
    {
        $this->enable();
        $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.index'))
            ->assertOk()
            ->assertSee('바이어 정산현황');
    }

    /** 채권관리와 같은 게이트 — 영업은 못 본다. */
    public function test_sales_cannot_open_the_screen(): void
    {
        $this->enable();
        $sales = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);

        $this->actingAs($sales)->get(route('erp.buyer-account.index'))->assertForbidden();
    }

    /** 메뉴도 토글을 따라간다 — 화면과 노출이 같은 출처를 봐야 한다(§8 #60). */
    public function test_sidebar_entry_follows_the_toggle(): void
    {
        $user = $this->finance();

        $this->actingAs($user)->get(route('erp.receivables.index'))
            ->assertOk()->assertDontSee('바이어 정산현황');

        $this->enable();

        $this->actingAs($user)->get(route('erp.receivables.index'))
            ->assertOk()->assertSee('바이어 정산현황');
    }

    // ── 미수 목록 ────────────────────────────────────────────────

    /** 완납 차량은 안 나온다 — 「받을 돈」 화면이라 0 은 볼 이유가 없다. */
    public function test_only_unpaid_vehicles_are_listed(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $unpaid = $this->vehicle($buyer);
        $paid = $this->vehicle($buyer);
        $this->actingAs($this->finance());
        // 게이트가 살아 있으므로 현금을 먼저 넣는다(이 테스트의 관심사는 아니다).
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $paid->id, 'type' => 'balance', 'amount' => 10000,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);

        $rows = app(BuyerAccountService::class)->unpaidVehicles($buyer);

        $this->assertSame([$unpaid->id], $rows->pluck('id')->all());
    }

    // ── 현금 사용 내역 (jin 2026-09-05 — "어떤 차량에 얼만큼 썼는지가 없네") ──────

    /**
     * 🚨 **이 기능의 원래 요구가 이것이다** — "이 10,000 이 어떻게 쓰였는지 투명하게".
     *    처음엔 이 화면에 없었다(바이어 편집 패널의 현금 탭에만 있었다).
     */
    public function test_screen_shows_which_vehicle_each_receipt_paid(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000, 'note' => '전신환 #1',
        ]);
        FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance', 'amount' => 4000,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertStringContainsString(__('buyer_account.usage_title'), $html);
        $this->assertStringContainsString('전신환 #1', $html, '어느 입금인지 안 보인다');
        $this->assertStringContainsString('4,000.00', $html, '그 차에 얼마 갔는지 안 보인다');
        $this->assertStringContainsString($vehicle->vehicle_number, $html);
    }

    /**
     * 🚨 **현금으로 완납된 차도 나와야 한다.** 그 차는 미수 0 이라 「미수 차량」 표에서 빠진다 —
     *    그래서 이 표가 따로 필요하다. 안 그러면 돈이 어디로 갔는지 화면에서 사라진다.
     */
    public function test_fully_paid_vehicle_still_appears_in_cash_usage(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);   // 총판매가 10,000
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance', 'amount' => 10000,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);

        // 전제 — 완납이라 미수 목록엔 없다.
        $this->assertCount(0, app(BuyerAccountService::class)->unpaidVehicles($buyer));

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertStringContainsString($vehicle->vehicle_number, $html,
            '현금으로 완납된 차가 화면에서 통째로 사라졌다');
    }

    /**
     * 🚨 사용 내역은 **검색으로 거르지 않는다** — 현금 원장이라 일부만 보이면
     *    「남은 현금」과 더해도 안 맞는 표가 된다.
     */
    public function test_cash_usage_is_not_filtered_by_the_search(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer, ['container_number' => 'OTHER']);
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance', 'amount' => 4000,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->set('search', 'NOTHING-MATCHES')
            ->call('searchNow')
            ->html();

        $this->assertStringContainsString('4,000.00', $html, '검색이 현금 사용 내역까지 걸렀다');
    }

    /** 엑셀에도 같은 내용이 들어간다 — 화면에만 있으면 밖으로 못 낸다. */
    public function test_export_has_the_cash_usage_sheet(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $vehicle = $this->vehicle($buyer);
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 10000,
        ]);
        FinalPayment::create([
            'vehicle_id' => $vehicle->id, 'type' => 'balance', 'amount' => 4000,
            'payment_date' => '2026-09-04', 'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.export', ['buyer' => $buyer->id]));
        $response->assertOk();
        $binary = $response->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'ba_').'.xlsx';
        file_put_contents($path, $binary);
        $ss = IOFactory::createReader('Xlsx')->load($path);
        $names = $ss->getSheetNames();
        $ss->disconnectWorksheets();
        @unlink($path);

        $this->assertContains('현금 사용', $names, '현금 사용 시트가 없다');
    }

    // ── 대량 대비 · 정렬 (jin 2026-09-05) ─────────────────────────

    /**
     * 🚨 **완납 차량을 통째로 불러오면 안 된다.** 종전엔 그 바이어의 판매 차량을 전부 읽고
     *    (+각 차의 잔금·회수이력까지) PHP 로 걸렀다 — 운영 실측 buyer 14 는 **252대를 불러 1대**를
     *    보여주고 있었다. ssancarerp 에서 정산처리·관리자 대시보드가 느려졌던 그 형태다.
     *
     * 실행된 SQL 을 세서, 완납 차량이 결과에서 빠지는 게 아니라 **애초에 안 읽히는지**를 본다.
     */
    public function test_paid_vehicles_are_filtered_in_sql_not_in_php(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $unpaid = $this->vehicle($buyer);
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 100000,
        ]);
        // 완납 차량 5대 — 결과엔 없어야 하고, **불러오지도 않아야** 한다.
        $paidIds = [];
        for ($i = 0; $i < 5; $i++) {
            $v = $this->vehicle($buyer);
            FinalPayment::create([
                'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10000,
                'payment_date' => '2026-09-04', 'confirmed_at' => now(),
            ]);
            $paidIds[] = $v->id;
        }

        $bindings = [];
        DB::listen(function ($q) use (&$bindings) {
            $bindings[] = $q->sql;
        });
        $rows = app(BuyerAccountService::class)->unpaidVehicles($buyer);
        DB::flushQueryLog();

        $this->assertSame([$unpaid->id], $rows->pluck('id')->all());

        // 미수 필터가 SQL 에 실제로 들어갔는지 — 안 들어가면 완납 5대를 다 읽는다.
        $vehicleQuery = collect($bindings)->first(fn ($sql) => str_contains($sql, 'from "vehicles"'));
        $this->assertNotNull($vehicleQuery);
        $this->assertStringContainsString('sale_unpaid_amount_krw_cache', $vehicleQuery,
            '미수 필터가 SQL 에 없다 — 완납 차량까지 전부 불러온다');
    }

    /**
     * ⚠️ 위 SQL 필터는 **캐시 컬럼**을 쓴다. 환율 미입력 외화차는 캐시가 null 이라 놓칠 수 있어
     *    안전망으로 함께 집는다(운영 실측 0건이지만 0 을 전제로 코드를 쓰지 않는다).
     */
    public function test_cache_null_vehicle_is_still_listed(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $v = $this->vehicle($buyer);
        DB::table('vehicles')->where('id', $v->id)
            ->update(['sale_unpaid_amount_krw_cache' => null]);

        $rows = app(BuyerAccountService::class)->unpaidVehicles($buyer);

        $this->assertSame([$v->id], $rows->pluck('id')->all(),
            '캐시가 null 인 차가 목록에서 사라졌다 — 환율 미입력 외화차가 조용히 빠진다');
    }

    /**
     * 🚨 **금액 정렬은 그 차량 통화 기준**이다(jin 2026-09-05).
     *    원화 캐시로 줄 세우면 **보이는 숫자와 순서가 어긋난다** — 이 표는 바이어에게 그대로
     *    나가고 ssancar.com 에도 미러되므로 혼동만 커진다.
     */
    public function test_amount_sort_uses_the_vehicle_currency_not_krw(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();

        // 외화로는 A(900) < B(1,000) 인데, 환율 때문에 **원화로는 뒤집힌다**.
        $small = $this->vehicle($buyer, ['sale_price' => 900, 'exchange_rate' => 2000]);   // ₩1,800,000
        $big = $this->vehicle($buyer, ['sale_price' => 1000, 'exchange_rate' => 1000]);    // ₩1,000,000

        $rows = app(BuyerAccountService::class)->unpaidVehicles($buyer, '', '', 'unpaid', 'desc');

        $this->assertSame([$big->id, $small->id], $rows->pluck('id')->all(),
            '원화 기준으로 정렬됐다 — 화면에 보이는 외화 순서와 어긋난다');
    }

    /** 통화가 섞이면 통화로 묶는다 — EUR 900 과 JPY 100,000 을 그냥 비교하면 뜻이 없다. */
    public function test_amount_sort_groups_by_currency(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['currency' => 'JPY', 'sale_price' => 100000]);
        $this->vehicle($buyer, ['currency' => 'EUR', 'sale_price' => 900]);

        $rows = app(BuyerAccountService::class)->unpaidVehicles($buyer, '', '', 'unpaid', 'desc');

        $this->assertSame(['EUR', 'JPY'], $rows->pluck('currency')->all(), '통화로 안 묶였다');
    }

    /**
     * 🚨 **현금 사용 내역은 상한을 두고 읽는다.** 이 원장은 줄지 않는다 — 잔금 확정 1건당
     *    배분 1행이라 몇 년이면 수백~수천 행이 된다. 전부 그리면 이 화면만 느려진다.
     */
    public function test_cash_usage_is_capped_and_pages(): void
    {
        $this->enable();
        $this->actingAs($this->finance());
        $buyer = $this->buyer();
        for ($i = 1; $i <= 14; $i++) {
            BuyerCashReceipt::create([
                'buyer_id' => $buyer->id, 'currency' => 'EUR',
                'received_date' => '2026-09-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'amount' => 100 + $i, 'note' => 'R'.$i,
            ]);
        }

        $c = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id);

        // 기본은 10건까지 — 최근 입금부터.
        $c->assertSee('R14')->assertDontSee('R3');

        $c->call('showMoreUsage')->assertSee('R3');

        // 🚨 **화면에서 자르는 것만으로는 부족하다** — 그러면 전부 읽고 나서 버리는 것이라
        //    느려지는 원인은 그대로다. 읽는 쿼리 자체에 상한이 걸려야 한다.
        //    (처음에 화면만 검사했더니 상한을 빼도 초록이었다.)
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });
        app(BuyerAccountService::class)->cashUsage($buyer, 11);

        $receiptQuery = collect($sql)->first(fn ($q) => str_contains($q, 'from "buyer_cash_receipts"'));
        $this->assertNotNull($receiptQuery);
        $this->assertStringContainsString('limit', strtolower($receiptQuery),
            '현금 사용 내역을 상한 없이 전부 읽는다 — 입금이 쌓이면 이 화면만 느려진다');
    }

    // ── 묶음별 ───────────────────────────────────────────────────

    /**
     * 값이 **정확히 같은 것끼리만** 묶고, 앞뒤 공백은 정규화한다.
     *
     * ⚠️ 모델(`Concerns\TrimsStringAttributes`)이 저장할 때 이미 trim 하므로, 그냥 만들면
     *    서비스의 trim 이 검사되지 않는다(처음에 그렇게 짰다가 trim 을 빼도 초록이었다).
     *    그래서 **query builder 로 공백이 든 값을 직접 밀어넣어** 서비스가 실제로 그걸 받게 한다.
     */
    public function test_groups_by_axis_and_trims_whitespace(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'ABCD1234567']);
        $padded = $this->vehicle($buyer);
        $this->vehicle($buyer, ['container_number' => 'ZZZZ9999999']);

        DB::table('vehicles')
            ->where('id', $padded->id)
            ->update(['container_number' => '  ABCD1234567  ']);

        $service = app(BuyerAccountService::class);
        $vehicles = $service->unpaidVehicles($buyer);
        $this->assertSame('  ABCD1234567  ', $vehicles->firstWhere('id', $padded->id)->getRawOriginal('container_number'),
            '공백이 안 든 값이 들어와 trim 을 검사하지 못한다');

        $groups = $service->groupsBy('container', $vehicles);

        $this->assertCount(2, $groups);
        $this->assertSame('ABCD1234567', $groups[0]['key']);
        $this->assertSame(2, $groups[0]['count']);
        $this->assertSame(20000.0, $groups[0]['unpaid']);
    }

    /** 빈 값은 「미지정」으로 모으고 **항상 마지막**에 둔다. */
    public function test_unassigned_group_goes_last(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => null]);
        $this->vehicle($buyer, ['container_number' => 'ABCD1234567']);

        $service = app(BuyerAccountService::class);
        $groups = $service->groupsBy('container', $service->unpaidVehicles($buyer));

        $this->assertSame('ABCD1234567', $groups[0]['key']);
        $this->assertSame('', $groups[1]['key'], '미지정이 마지막이 아니다');
    }

    /**
     * 🚨 **통화가 다르면 합치지 않는다.** 한 묶음에 EUR·USD 가 섞였는데 더하면
     *    아무 뜻도 없는 숫자가 나온다 — 그런 합계는 사람이 그대로 믿는다.
     */
    public function test_currencies_are_never_summed_together(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'SAME', 'currency' => 'EUR']);
        $this->vehicle($buyer, ['container_number' => 'SAME', 'currency' => 'USD']);

        $service = app(BuyerAccountService::class);
        $groups = $service->groupsBy('container', $service->unpaidVehicles($buyer));

        $this->assertCount(2, $groups, '통화가 다른데 한 줄로 합쳐졌다');
        $this->assertEqualsCanonicalizing(['EUR', 'USD'], array_column($groups, 'currency'));
    }

    /** 4축 전부 동작해야 한다 — 하나만 되면 나머지는 조용히 빈 표가 된다. */
    public function test_every_axis_groups_something(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, [
            'container_number' => 'C1', 'export_declaration_number' => 'D1',
            'bl_number' => 'B1', 'vessel_name' => 'V1',
        ]);

        $service = app(BuyerAccountService::class);
        foreach (['container' => 'C1', 'declaration' => 'D1', 'bl' => 'B1', 'vessel' => 'V1'] as $axis => $expected) {
            $groups = $service->groupsBy($axis, $service->unpaidVehicles($buyer));
            $this->assertSame($expected, $groups[0]['key'], "{$axis} 축이 안 묶인다");
        }
    }

    // ── 현금 요약 ────────────────────────────────────────────────

    /** 화면 요약과 게이트가 쓰는 balanceFor 가 갈리면 안 된다. */
    public function test_cash_summary_matches_the_single_source(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        BuyerCashReceipt::create([
            'buyer_id' => $buyer->id, 'currency' => 'EUR',
            'received_date' => '2026-09-01', 'amount' => 7000,
        ]);

        $cash = app(BuyerAccountService::class)->cashByCurrency($buyer);

        $this->assertSame(
            BuyerCashReceipt::balanceFor($buyer->id, 'EUR'),
            $cash['EUR']['remaining'],
        );
        $this->assertSame(7000.0, $cash['EUR']['received']);
        $this->assertSame(0.0, $cash['EUR']['allocated']);
    }

    // ── 엑셀 ─────────────────────────────────────────────────────

    /**
     * 🚨 **화면과 엑셀이 같은 조건**이어야 한다. 조건을 컨트롤러에 옮겨 적으면
     *    「화면엔 3대인데 엑셀엔 300대」가 된다 — 에러 없이 조용히(SKILLS §9).
     *    그래서 둘 다 같은 서비스를 쓰는지 **행 수로 대조**한다.
     */
    public function test_export_matches_the_screen_and_is_logged(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'C1']);
        $this->vehicle($buyer, ['container_number' => 'C1']);
        $other = $this->buyer();
        $this->vehicle($other);   // 다른 바이어 — 엑셀에 섞이면 안 된다

        // 화면이 실제로 그 두 대를 그리는지 먼저 본다.
        Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->assertSee($buyer->vehicles()->orderBy('vehicle_number')->first()->vehicle_number);

        $expected = app(BuyerAccountService::class)->unpaidVehicles($buyer)->count();

        $response = $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.export', ['buyer' => $buyer->id, 'axis' => 'container']));
        $response->assertOk();
        $response->streamedContent();   // streamDownload 를 실제로 흘려봐야 예외가 드러난다

        $this->assertDatabaseHas('export_logs', ['target' => 'buyer_account', 'row_count' => $expected]);
        $this->assertSame(2, $expected, '다른 바이어 차량이 섞였다');
    }

    /** 토글이 꺼졌으면 엑셀도 없다 — 화면만 막고 링크를 열어두면 우회된다. */
    public function test_export_is_404_while_the_toggle_is_off(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.export', ['buyer' => $buyer->id]))
            ->assertNotFound();
    }

    /** 축 값은 화이트리스트 — 모르는 값이 오면 기본축으로 떨어진다(SQL 에 안 흘러간다). */
    public function test_unknown_axis_falls_back(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'C1']);

        $groups = app(BuyerAccountService::class)
            ->groupsBy('vehicles; DROP TABLE users', app(BuyerAccountService::class)->unpaidVehicles($buyer));

        $this->assertSame('C1', $groups[0]['key'], '알 수 없는 축이 기본축으로 안 떨어졌다');
    }

    // ── 검색 · 콤보박스 · 차대번호 (jin 2026-09-05) ────────────────

    /**
     * 🚫 바이어 선택은 **그냥 select 가 아니라 검색되는 콤보박스**여야 한다(프로젝트 표준).
     *    바이어가 수백이면 스크롤로 못 찾는다.
     */
    public function test_buyer_picker_is_a_searchable_combobox(): void
    {
        $this->enable();
        $this->buyer();

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')->html();

        // x-erp.combobox 가 렌더하는 표식 — 타이핑으로 걸러지는 목록.
        $this->assertStringContainsString('filtered()', $html, '검색되는 콤보박스가 아니다');
        $this->assertStringNotContainsString('wire:model.live="buyerId"', $html, 'select 로 되돌아갔다');
    }

    /** 차대번호는 차량번호 **바로 다음**에 온다(jin 지정 순서). */
    public function test_vin_column_sits_right_after_the_plate(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['nice_reg_vin' => 'KMHXX00XXXX000001']);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertStringContainsString('KMHXX00XXXX000001', $html, '차대번호가 안 보인다');
        $plate = strpos($html, __('buyer_account.col_vehicle'));
        $vin = strpos($html, __('buyer_account.col_vin'));
        $stage = strpos($html, __('buyer_account.col_progress'));
        $this->assertTrue($plate < $vin && $vin < $stage, '차대번호가 차량번호와 진행상태 사이가 아니다');
    }

    /**
     * 🚨 검색은 **차량관리와 같은 조건**이어야 한다 — 조건이 두 벌이 되면
     *    「차량관리에선 찾히는데 여기선 안 찾히는」 형태가 된다(SKILLS §8 #45).
     *    그래서 같은 스코프를 쓰는지 **결과 집합으로 대조**한다.
     */
    public function test_search_uses_the_same_scope_as_the_vehicle_list(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $hit = $this->vehicle($buyer, ['container_number' => 'FIND-ME-1']);
        $this->vehicle($buyer, ['container_number' => 'OTHER']);

        $service = app(BuyerAccountService::class);
        $found = $service->unpaidVehicles($buyer, 'FIND-ME')->pluck('id')->all();
        $this->assertSame([$hit->id], $found);

        // 같은 검색어를 차량관리가 쓰는 스코프에 그대로 넣어도 같은 차가 나와야 한다.
        $viaScope = Vehicle::query()->where('buyer_id', $buyer->id)->searchAny('FIND-ME')->pluck('id')->all();
        $this->assertSame($found, $viaScope, '검색 조건이 차량관리와 갈렸다');
    }

    /** 차대번호 칸은 별도다 — 끝자리로도 찾힌다. */
    public function test_vin_search_works(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $hit = $this->vehicle($buyer, ['nice_reg_vin' => 'KMHXX00XXXX987654']);
        $this->vehicle($buyer, ['nice_reg_vin' => 'KMHXX00XXXX111111']);

        $found = app(BuyerAccountService::class)->unpaidVehicles($buyer, '', '987654')->pluck('id')->all();

        $this->assertSame([$hit->id], $found);
    }

    /**
     * 🚨 **화면이 실제로 검색을 반영해야 한다.** 서비스만 검사하면 컴포넌트가 검색어를
     *    안 넘겨도 초록이다 — 실제로 그랬다(2026-09-05, 이 테스트를 넣고서야 드러났다).
     */
    public function test_screen_filters_rows_by_the_search_box(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $hit = $this->vehicle($buyer, ['container_number' => 'FIND-ME-1']);
        $miss = $this->vehicle($buyer, ['container_number' => 'OTHER-1']);

        Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->set('search', 'FIND-ME')
            ->call('searchNow')
            ->assertSee($hit->vehicle_number)
            ->assertDontSee($miss->vehicle_number);
    }

    /** 차대번호 칸도 화면에서 걸러야 한다. */
    public function test_screen_filters_rows_by_the_vin_box(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $hit = $this->vehicle($buyer, ['nice_reg_vin' => 'KMHXX00XXXX987654']);
        $miss = $this->vehicle($buyer, ['nice_reg_vin' => 'KMHXX00XXXX111111']);

        Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->set('vinSearch', '987654')
            ->call('searchNow')
            ->assertSee($hit->vehicle_number)
            ->assertDontSee($miss->vehicle_number);
    }

    /**
     * 배치 (jin 2026-09-05) — **바이어 · 통합검색 · 차대번호 · 조회 네 개가 한 줄**에 나란히.
     *
     * 🚫 하나로 합치지 말 것 — 각자 다른 것을 고르고 찾는 칸이다.
     * ⚠️ 렌더 테스트는 실제 줄바꿈을 모른다 — 그래서 「같은 flex 행 안에 넷이 다 있는지」를 본다.
     *    바이어를 따로 빼거나 조회를 아래로 내리면 이 검사에서 밖으로 나간다.
     */
    public function test_four_controls_sit_in_one_row(): void
    {
        $this->enable();
        $this->buyer();

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')->html();
        $row = $this->balancedDiv($html, 'data-row="ba-filters"');

        foreach ([
            'filtered()' => '바이어 콤보박스',
            'wire:model="search"' => '통합검색 칸',
            'wire:model="vinSearch"' => '차대번호 칸',
            'wire:click="searchNow"' => '조회 버튼',
        ] as $needle => $label) {
            $this->assertStringContainsString($needle, $row, "{$label} 이 그 줄 밖에 있다");
        }

        // 순서도 본다 — 차대번호는 통합검색 바로 옆, 그 옆이 조회.
        $this->assertTrue(
            strpos($row, 'wire:model="search"') < strpos($row, 'wire:model="vinSearch"')
            && strpos($row, 'wire:model="vinSearch"') < strpos($row, 'wire:click="searchNow"'),
            '통합검색 → 차대번호 → 조회 순서가 아니다',
        );
    }

    /** `<div ... $marker ...>` 부터 짝이 맞는 `</div>` 까지를 잘라낸다. */
    private function balancedDiv(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, "행 표식({$marker})을 못 찾았다");
        $start = strrpos(substr($html, 0, $at), '<div');
        $this->assertNotFalse($start);

        $depth = 0;
        $i = $start;
        while ($i < strlen($html)) {
            $open = strpos($html, '<div', $i);
            $close = strpos($html, '</div>', $i);
            if ($close === false) {
                break;
            }
            if ($open !== false && $open < $close) {
                $depth++;
                $i = $open + 4;

                continue;
            }
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $close + 6 - $start);
            }
            $i = $close + 6;
        }

        $this->fail('행이 안 닫힌다');
    }

    /**
     * 검색칸은 **호버로 전체 대상**을 보여준다(jin 2026-09-05).
     * placeholder 는 좁아서 잘리므로 대표만 적고, 전문은 title 로 — 차량관리와 같은 방식.
     */
    public function test_search_boxes_have_hover_titles(): void
    {
        $this->enable();

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')->html();

        $this->assertStringContainsString('title="'.e(__('vehicle.search_title')).'"', $html,
            '통합검색에 호버(title)가 없다');
        $this->assertStringContainsString('title="'.e(__('vehicle.vin_ph')).'"', $html,
            '차대번호 칸에 호버(title)가 없다');
    }

    /** 🚨 화면 검색이 엑셀에 안 넘어가면 「화면엔 1대인데 엑셀엔 3대」가 된다(SKILLS §9). */
    public function test_export_honours_the_screen_search(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'FIND-ME-1']);
        $this->vehicle($buyer, ['container_number' => 'OTHER-1']);
        $this->vehicle($buyer, ['container_number' => 'OTHER-2']);

        $this->actingAs($this->finance())
            ->get(route('erp.buyer-account.export', ['buyer' => $buyer->id, 'q' => 'FIND-ME']))
            ->assertOk()
            ->streamedContent();

        $this->assertDatabaseHas('export_logs', ['target' => 'buyer_account', 'row_count' => 1]);
    }

    /** 화면에 미번역 키가 그대로 찍히면 안 된다(SKILLS §8 #73). */
    public function test_no_untranslated_key_leaks(): void
    {
        $this->enable();
        $buyer = $this->buyer();
        $this->vehicle($buyer, ['container_number' => 'C1']);

        $html = Volt::actingAs($this->finance())->test('erp.buyer-account.index')
            ->set('buyerId', (string) $buyer->id)
            ->html();

        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:buyer_account|nav|common)\.[a-z_]+(?:\.[a-z_]+)?\b/',
            $html,
            '번역 안 된 키가 화면에 그대로 찍힌다',
        );
    }
}
