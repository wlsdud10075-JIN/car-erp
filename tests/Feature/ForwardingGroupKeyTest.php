<?php

namespace Tests\Feature;

use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚢 **운임 묶음은 선적방법이 정한다** (jin 2026-09-21).
 *
 * jin: *「RORO면 선박명으로 묶고, CONTAINER면 컨테이너번호로 묶으면 돼.
 *        RORO여도 컨테이너번호에 RORO번호를 기입할 수 있어서 지금 이렇게 된 것 같은데」*
 *
 * 예전엔 컨테이너가 무조건 1순위였다. 그래서 **RORO 인데 컨테이너 칸에 RORO 번호를 적는** 회사에서
 * 묶음이 차 한 대당 하나로 쪼개졌다 — 묶음마다 운임 인보이스 폼이 한 벌이라 그대로 화면 크기다.
 *
 * 실측 (2026-09-21):
 *   ssancarerp  RORO 571건 중 568건이 컨테이너 칸 채움 → CIG 묶음 554 → **117**
 *   heymanerp   RORO  81건 중   0건                    → 묶음 12/13/11/5 **전부 무변화**
 *
 * 🚨 **묶음 키가 바뀌면 그 묶음에 붙은 인보이스가 미아가 된다** — `group_type`+`group_key` 로 붙는다.
 */
class ForwardingGroupKeyTest extends TestCase
{
    use RefreshDatabase;

    private ForwardingCompany $fc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fc = ForwardingCompany::create(['name' => 'CIG', 'is_active' => true]);
    }

    private function actor(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(string $plate, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'purchase_price' => 5_000_000, 'purchase_date' => '2026-07-01',
            'forwarding_company_id' => $this->fc->id, 'shipping_date' => now()->subDays(3)->format('Y-m-d'),
            'transport_fee' => 100,
        ], $attrs));
    }

    /** 화면이 실제로 만든 묶음 목록 [type|key => 대수]. */
    private function renderedGroups(): array
    {
        $this->actingAs($this->actor());
        $c = Volt::test('erp.forwarding-companies.index');
        $grouped = $c->instance()->groupedShipments[$this->fc->id] ?? collect();

        return $grouped->mapWithKeys(fn ($g) => [$g['type'].'|'.$g['key'] => $g['vehicles']->count()])->all();
    }

    // ── 규칙 ───────────────────────────────────────────────────────────

    /** 🚢 RORO 는 **선박명**으로 묶는다 — 컨테이너 칸에 뭐가 적혀 있어도. */
    public function test_roro_groups_by_vessel_even_when_the_container_field_is_filled(): void
    {
        $this->vehicle('11가1111', ['shipping_method' => 'RORO', 'vessel_name' => 'GLOVIS SUN', 'container_number' => 'RORO-A-01']);
        $this->vehicle('22나2222', ['shipping_method' => 'RORO', 'vessel_name' => 'GLOVIS SUN', 'container_number' => 'RORO-A-02']);

        $this->assertSame(['vessel|GLOVIS SUN' => 2], $this->renderedGroups(),
            'RORO 두 대가 같은 배인데 컨테이너 번호 때문에 따로 묶였다 — 묶음마다 인보이스 폼이 한 벌이다');
    }

    /** 📦 CONTAINER 는 **컨테이너번호**로 묶는다 — 같은 배라도 컨테이너가 다르면 따로. */
    public function test_container_groups_by_container_number(): void
    {
        $this->vehicle('33다3333', ['shipping_method' => 'CONTAINER', 'vessel_name' => 'ONE APUS', 'container_number' => 'ONEU5851400']);
        $this->vehicle('44라4444', ['shipping_method' => 'CONTAINER', 'vessel_name' => 'ONE APUS', 'container_number' => 'ONEU5851400']);
        $this->vehicle('55마5555', ['shipping_method' => 'CONTAINER', 'vessel_name' => 'ONE APUS', 'container_number' => 'TCLU9665052']);

        // 순서는 안 본다 — 화면이 미지급을 위로 올리므로 묶음 순서가 바뀔 수 있다.
        $got = $this->renderedGroups();
        ksort($got);
        $this->assertSame([
            'container|ONEU5851400' => 2,
            'container|TCLU9665052' => 1,
        ], $got);
    }

    /**
     * 🔑 **RORO 인데 선박명이 없으면 컨테이너로 떨어진다** — 빼면 안 되는 폴백이다.
     *    ssancarerp 에 이 상태가 99건 있고, 없애면 신고번호(차 1~2대 단위)로 흩어져 지금보다 나빠진다.
     */
    public function test_roro_without_a_vessel_falls_back_to_the_container_field(): void
    {
        $this->vehicle('66바6666', ['shipping_method' => 'RORO', 'vessel_name' => null, 'container_number' => 'RORO-99', 'export_declaration_number' => 'D-1']);

        $this->assertSame(['container|RORO-99' => 1], $this->renderedGroups(),
            '선박명 없는 RORO 가 신고번호로 흩어졌다');
    }

    /**
     * 🔀 선적방법이 비어 있으면 **선박 → 신고번호 → 컨테이너** (jin 2026-09-21).
     *    방법을 모르면 컨테이너 칸이 진짜 컨테이너인지 RORO 관리코드인지 알 수 없다 —
     *    ssancarerp 실측 4건 중 3건이 `6.07_A RORO 3-2_7` 꼴이라 컨테이너를 먼저 보면 대당 묶음이 된다.
     */
    public function test_an_empty_shipping_method_distrusts_the_container_field(): void
    {
        // 컨테이너 칸이 채워져 있어도 선박이 이긴다
        $this->vehicle('77사7777', ['shipping_method' => null, 'container_number' => '6.07_A RORO 3-2_7', 'vessel_name' => 'MV AH SHIN', 'export_declaration_number' => 'D-2']);
        $this->vehicle('78사7778', ['shipping_method' => null, 'container_number' => '6.07_A RORO 3-2_8', 'vessel_name' => 'MV AH SHIN', 'export_declaration_number' => 'D-3']);
        // 선박이 없으면 컨테이너보다 신고번호가 먼저
        $this->vehicle('79사7779', ['shipping_method' => null, 'container_number' => 'MRSU8948781', 'vessel_name' => null, 'export_declaration_number' => 'D-4']);

        $got = $this->renderedGroups();
        ksort($got);
        $this->assertSame(['declaration|D-4' => 1, 'vessel|MV AH SHIN' => 2], $got,
            '선적방법 없는 차가 컨테이너(=RORO 관리코드)로 대당 묶였다');
    }

    /** 마지막 폴백 = 수출신고번호. */
    public function test_the_last_resort_is_the_declaration_number(): void
    {
        $this->vehicle('88아8888', ['shipping_method' => 'RORO', 'vessel_name' => null, 'container_number' => null, 'export_declaration_number' => 'D-3']);

        $this->assertSame(['declaration|D-3' => 1], $this->renderedGroups());
    }

    // ── 기존 인보이스가 미아가 되지 않는다 ─────────────────────────────

    /**
     * 🚨 **이미 붙어 있는 인보이스를 잃지 않는다.**
     *    heymanerp 에 39건이 있고(container 26 · vessel 10 · declaration 3), 그 회사는 RORO 에
     *    컨테이너를 안 적어서 새 규칙에서도 묶음이 전부 그대로다. 그 성질을 여기서 못박는다.
     */
    public function test_an_existing_invoice_still_matches_its_group(): void
    {
        // heymanerp 형태 — CONTAINER 는 컨테이너로, RORO 는 컨테이너 칸이 비어 선박으로.
        $this->vehicle('99자9999', ['shipping_method' => 'CONTAINER', 'container_number' => 'MRKU5410824', 'vessel_name' => 'ONE APUS']);
        $this->vehicle('10차1010', ['shipping_method' => 'RORO', 'container_number' => null, 'vessel_name' => 'GLOVIS SUN']);

        foreach ([['container', 'MRKU5410824'], ['vessel', 'GLOVIS SUN']] as [$type, $key]) {
            ForwardingInvoice::create([
                'forwarding_company_id' => $this->fc->id, 'group_type' => $type, 'group_key' => $key,
                'currency' => 'USD', 'amount' => 1000, 'paid_at' => now(),
            ]);
        }

        $keys = array_keys($this->renderedGroups());
        sort($keys);
        $this->assertSame(['container|MRKU5410824', 'vessel|GLOVIS SUN'], $keys,
            '묶음 키가 바뀌어 기존 인보이스가 미아가 된다');

        // 화면이 그 인보이스를 실제로 찾아 「지급완료」로 인식하는가
        $this->actingAs($this->actor());
        $c = Volt::test('erp.forwarding-companies.index');
        $invoices = $c->instance()->invoices;
        foreach ([['container', 'MRKU5410824'], ['vessel', 'GLOVIS SUN']] as [$type, $key]) {
            $this->assertNotNull($invoices[$this->fc->id.'|'.$type.'|'.$key] ?? null,
                "{$type}|{$key} 인보이스를 못 찾는다");
        }
    }

    /** 🧮 같은 배 RORO 여러 대가 **한 묶음**이 된다 — 폼이 대수만큼 그려지지 않는다. */
    public function test_many_roro_cars_on_one_vessel_make_one_group(): void
    {
        foreach (range(1, 12) as $i) {
            $this->vehicle(sprintf('%02d타%04d', $i, $i), [
                'shipping_method' => 'RORO', 'vessel_name' => 'MORNING CLARA',
                'container_number' => 'RORO-'.$i,   // 대당 다른 번호 — 예전엔 이게 12묶음이었다
            ]);
        }

        $this->assertSame(['vessel|MORNING CLARA' => 12], $this->renderedGroups(),
            '12대가 12묶음으로 쪼개졌다 — 인보이스 폼이 12벌 그려진다');
    }
}
