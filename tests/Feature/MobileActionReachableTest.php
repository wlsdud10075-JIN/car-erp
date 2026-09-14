<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📱 **폰에서도 할 수 있어야 하는 조작** (jin 2026-09-14).
 *
 * jin: *「많이는 안 쓰긴 하지만.. 급하면 모바일로도 되게 해야하지 않을까?」*
 *
 * 모바일 카드는 「읽기 전용 요약」으로 만들어져 있었다 — **변경 조작이 `hidden sm:block` 표 안에만**
 * 있고 편집 패널 푸터에도 없어 폰에서는 **우회로가 0** 이었다.
 * 삭제(차량·바이어·컨사이니·영업담당자) · 담당 승계 · 선수금 성격 변경이 그랬다.
 *
 * 🧭 **삭제는 목록 카드가 아니라 패널 푸터에 둔다.** 카드 자체가 「누르면 편집 열림」이라
 *    거기 삭제를 붙이면 오탭이 곧 사고가 된다. 패널은 이미 그 항목을 연 상태라 의도가 분명하다.
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다** — 데스크탑에선 표에 버튼이 있어 전부 동작한다.
 *    없어지는 건 폰에서의 경로뿐이라 **렌더 결과에 그 버튼이 있는지** 센다.
 */
class MobileActionReachableTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function super(): User
    {
        return User::factory()->create([
            'permission' => 'super', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function buyer(): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);
    }

    /** 패널을 열면 폰 전용 삭제가 보인다 — 데스크탑에선 `sm:hidden` 이라 안 보인다. */
    public function test_delete_is_reachable_from_every_edit_panel_on_mobile(): void
    {
        $user = $this->super();
        $buyer = $this->buyer();

        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'C1', 'is_active' => true]);
        $vehicle = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1400, 'dhl_request' => false,
            'buyer_id' => $buyer->id, 'salesman_id' => $buyer->salesman_id,
        ]);

        $cases = [
            ['erp.buyers.index', $buyer->id, 'buyer.delete_confirm_simple'],
            ['erp.consignees.index', $consignee->id, 'consignee.delete_confirm_simple'],
            ['erp.salesmen.index', $buyer->salesman_id, 'salesman.delete_confirm_simple'],
            ['erp.vehicles.index', $vehicle->id, 'vehicle.delete_confirm_simple'],
        ];

        foreach ($cases as [$component, $id, $key]) {
            $html = Volt::actingAs($user)->test($component)->call('openEdit', $id)->html();

            $this->assertStringContainsString(__($key), $html,
                "{$component} 패널에 폰 전용 삭제가 없다 — 폰에서 지울 방법이 사라진다");
        }
    }

    /** 담당 승계도 폰에서 — 퇴사 처리 흐름이라 「급할 때」가 실제로 생긴다. */
    public function test_handover_is_reachable_on_mobile(): void
    {
        $buyer = $this->buyer();

        $html = Volt::actingAs($this->super())->test('erp.salesmen.index')
            ->call('openEdit', $buyer->salesman_id)->html();

        $this->assertStringContainsString(__('salesman.handover.button'), $html,
            '영업담당자 패널에 승계가 없다 — 폰에서 퇴사 처리를 못 한다');
    }

    /**
     * 선수금 「성격」은 나중에 정하는 값인데 폰에선 **글자로만** 보여 고칠 방법이 없었다.
     * 이 화면엔 편집 패널이 없어 우회로가 「신규 등록」뿐이었다.
     */
    public function test_advance_receipt_nature_is_editable_on_mobile(): void
    {
        AdvanceReceipt::create([
            'company_name' => '데모상사', 'amount' => 1_000_000,
            'received_date' => '2026-09-01', 'nature' => array_key_first(AdvanceReceipt::NATURES),
        ]);

        $html = Volt::actingAs($this->super())->test('erp.deposits.index')->set('tab', 'advance')->html();

        // 표(데스크탑) + 카드(모바일) 두 곳에 있어야 한다. 하나면 폰에서 못 고친다.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'setNature('),
            '선수금 성격 선택칸이 한 곳뿐이다 — 폰에서 고칠 방법이 없다');
    }

    /**
     * 🚫 **상환된 선수금은 잠긴다** — 바꾸면 「갚은 돈」과 「대표 자산」 사이를 오간다.
     *    데스크탑 표가 지키는 규칙을 모바일 카드도 똑같이 지켜야 한다.
     */
    public function test_a_repaid_advance_receipt_stays_locked_on_mobile_too(): void
    {
        AdvanceReceipt::create([
            'company_name' => '상환완료상사', 'amount' => 500_000,
            'received_date' => '2026-08-01', 'nature' => array_key_first(AdvanceReceipt::NATURES),
            'repaid_at' => now(),
        ]);

        // ⚠️ `showRepaid` 를 켜야 상환된 행이 목록에 나온다 — 안 켜면 렌더 자체가 안 돼
        //    **아무것도 검사하지 않은 채 통과**한다(처음에 그렇게 헛돌았다, §8 #73).
        $c = Volt::actingAs($this->super())->test('erp.deposits.index')
            ->set('tab', 'advance')->set('showRepaid', true);

        $html = $c->html();
        $this->assertStringContainsString('상환완료상사', $html, '상환된 행이 목록에 없다 — 전제가 안 선다');

        $this->assertStringNotContainsString('setNature(', $html,
            '상환된 행까지 성격을 바꿀 수 있다 — 데스크탑 표의 잠금 규칙과 어긋난다');
    }

    /**
     * 🧭 폰 전용 조작은 `sm:hidden` 이어야 한다 — 안 붙이면 데스크탑에 같은 버튼이 두 번 생긴다
     *    (표에 하나, 패널에 하나). 정적으로 확인한다.
     */
    public function test_mobile_only_actions_are_hidden_on_desktop(): void
    {
        $files = [
            'resources/views/livewire/erp/buyers/index.blade.php' => 'buyer.delete_confirm_simple',
            'resources/views/livewire/erp/consignees/index.blade.php' => 'consignee.delete_confirm_simple',
            'resources/views/livewire/erp/salesmen/index.blade.php' => 'salesman.delete_confirm_simple',
            'resources/views/livewire/erp/vehicles/index.blade.php' => 'vehicle.delete_confirm_simple',
        ];

        foreach ($files as $rel => $marker) {
            $lines = file(base_path($rel));
            $found = false;

            foreach ($lines as $i => $line) {
                if (! str_contains($line, $marker)) {
                    continue;
                }
                $found = true;
                // 버튼 자체 또는 **감싸는 묶음**에 sm:hidden 이 있어야 한다.
                // ⚠️ 창을 좁게 잡으면 못 본다 — 영업담당자는 승계 버튼이 사이에 끼어
                //    묶음 div 가 표식보다 8줄 위에 있다(실제로 그렇게 헛걸렸다).
                $window = implode('', array_slice($lines, max(0, $i - 18), 24));
                $this->assertStringContainsString('sm:hidden', $window,
                    "{$rel} 의 폰 전용 삭제에 sm:hidden 이 없다 — 데스크탑에 버튼이 두 개가 된다");
            }

            $this->assertTrue($found, "표식을 못 찾았다 — 구조가 바뀌었으면 이 테스트도 고칠 것: {$rel}");
        }
    }
}
