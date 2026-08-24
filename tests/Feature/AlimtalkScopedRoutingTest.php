<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🎯 **체크박스는 「받을지」, 역할은 「무엇을 받을지」** (jin 2026-08-24).
 *
 * 개편 전에는 두 갈래가 섞여 있었다 — 역할 체크는 **그룹 전원에게 전량**을 뿌리고,
 * 담당자 발송은 **코드에 박혀** 화면에 안 보였다. 그래서 화면만 보고 「영업이 안 받네」 하고
 * 체크하면 자동 발송과 겹쳐 **중복 + 남의 차 유출**이 났다.
 *
 * 실사고(2026-08-24, heymanerp): 보증금매입독촉·신규차량등록에 '영업'이 켜져 있어
 * 무사백 담당 차량의 바이어·매입가가 영업 8명 전원에게 나갔다.
 */
class AlimtalkScopedRoutingTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** 영업 user + 연결된 salesman. */
    private function sales(?string $phone): array
    {
        $u = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'phone' => $phone, 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'user_id' => $u->id]);

        return [$u->fresh(), $sm];
    }

    private function vehicle(Salesman $sm): Vehicle
    {
        $b = Buyer::create(['name' => 'B'.++$this->n, 'is_active' => true, 'salesman_id' => $sm->id]);

        return Vehicle::create([
            'vehicle_number' => 'SC'.++$this->n.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000,
        ]);
    }

    private function broad(string $permission, string $phone): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now(),
        ]);
    }

    private function selectRoles(string $code, array $roles): void
    {
        Setting::updateOrCreate(
            ['key' => "alimtalk_roles_{$code}_".Setting::companyTemplateSet()],
            ['value' => implode(',', $roles), 'type' => 'string'],
        );
    }

    // ── 핵심: 영업은 남의 차를 못 받는다 ──────────────────────

    public function test_a_salesman_only_ever_gets_their_own_vehicles(): void
    {
        [, $mine] = $this->sales('010-1111-1111');
        [, $theirs] = $this->sales('010-2222-2222');
        $a = $this->vehicle($mine);
        $b = $this->vehicle($theirs);

        $this->selectRoles('erp_sale_unpaid', ['영업']);
        $map = AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$a, $b]);

        $this->assertSame([$a->id], $map['010-1111-1111']->pluck('id')->all(),
            '영업이 남의 차를 받으면 바이어·금액이 통째로 퍼진다 — 2026-08-24 실사고');
        $this->assertSame([$b->id], $map['010-2222-2222']->pluck('id')->all());
    }

    /** 🚨 체크가 유일한 스위치 — 안 켰으면 담당자라도 안 받는다(코드에 박힌 자동 발송 없음). */
    public function test_nobody_gets_anything_when_no_role_is_checked(): void
    {
        [, $sm] = $this->sales('010-1111-1111');
        $v = $this->vehicle($sm);

        $this->selectRoles('erp_sale_unpaid', []);

        $this->assertSame([], AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$v]),
            '체크를 안 했는데 누군가 받으면 화면이 더 이상 진실이 아니다');
    }

    // ── 역할별 범위 ────────────────────────────────────────────

    public function test_manager_and_admin_get_everything_without_being_assigned(): void
    {
        [, $sm] = $this->sales('010-1111-1111');
        $a = $this->vehicle($sm);
        $b = $this->vehicle($sm);

        $this->broad('manager', '010-3333-3333');
        $this->broad('admin', '010-4444-4444');

        $this->selectRoles('erp_sale_unpaid', ['manager', 'admin']);
        $map = AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$a, $b]);

        foreach (['010-3333-3333', '010-4444-4444'] as $phone) {
            $this->assertCount(2, $map[$phone], '업무관리자·최고관리자는 배정과 무관하게 전체를 본다');
        }
    }

    /** [관리]는 본인 팀 영업의 차만. */
    public function test_team_manager_sees_only_their_own_team(): void
    {
        [$mineUser, $mine] = $this->sales('010-1111-1111');
        [, $theirs] = $this->sales('010-2222-2222');
        $a = $this->vehicle($mine);
        $b = $this->vehicle($theirs);

        $mgr = User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'phone' => '010-5555-5555', 'email_verified_at' => now(),
        ]);
        $mineUser->update(['manager_user_id' => $mgr->id]);

        $this->selectRoles('erp_sale_unpaid', ['관리']);
        $map = AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$a, $b]);

        $this->assertSame([$a->id], $map['010-5555-5555']->pluck('id')->all(),
            '[관리]가 팀 밖 차를 받으면 팀 스코프가 의미를 잃는다');
    }

    // ── 실무에서 실제로 걸리는 것들 ────────────────────────────

    /** 전화 없는 사람은 조용히 빠진다 — 빈 키가 생기면 그 발송이 통째로 skip 된다. */
    public function test_people_without_a_phone_are_dropped(): void
    {
        [, $sm] = $this->sales(null);
        $v = $this->vehicle($sm);

        $this->selectRoles('erp_sale_unpaid', ['영업']);

        $this->assertSame([], AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$v]));
    }

    /** users.phone 이 비면 salesmen.phone 으로 폴백 — 구 픽업 발송이 후자를 썼다. */
    public function test_salesman_phone_is_used_when_the_user_has_none(): void
    {
        [, $sm] = $this->sales(null);
        $sm->update(['phone' => '010-9999-9999']);
        $v = $this->vehicle($sm);

        $this->selectRoles('erp_pickup_reminder', ['영업']);
        $map = AlimtalkRecipients::scopedFor('erp_pickup_reminder', [$v]);

        $this->assertArrayHasKey('010-9999-9999', $map,
            '회사에 따라 users.phone 이 비어 있다 — 폴백이 없으면 그 회사 픽업이 통째로 멈춘다');
    }

    /** 한 사람이 두 그룹에 걸려도 한 번만, 합집합으로. */
    public function test_a_person_in_two_groups_is_listed_once(): void
    {
        [, $sm] = $this->sales('010-1111-1111');
        $v = $this->vehicle($sm);
        $this->broad('manager', '010-3333-3333');

        $this->selectRoles('erp_sale_unpaid', ['manager', '관리']);
        $map = AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$v]);

        $this->assertCount(1, $map['010-3333-3333']);
    }

    /**
     * 🚨 담당자 없는 차량은 영업·관리 스코프에 안 들어간다 — **조용히 아무도 안 받는다**.
     * 업무관리자·admin 이 켜져 있으면 그 몫이 덮인다.
     */
    public function test_a_vehicle_with_no_salesman_only_reaches_the_broad_roles(): void
    {
        [, $sm] = $this->sales('010-1111-1111');
        $orphan = $this->vehicle($sm);
        $orphan->forceFill(['salesman_id' => null])->saveQuietly();
        $this->broad('manager', '010-3333-3333');

        $this->selectRoles('erp_sale_unpaid', ['영업', 'manager']);
        $map = AlimtalkRecipients::scopedFor('erp_sale_unpaid', [$orphan->fresh()]);

        $this->assertArrayNotHasKey('010-1111-1111', $map, '담당자가 없으니 영업은 못 받는다');
        $this->assertCount(1, $map['010-3333-3333'], '업무관리자가 그 몫을 덮는다');
    }

    // ── 회귀 방지 ──────────────────────────────────────────────

    /**
     * 🔒 정적 검사 — 「담당자에게 자동 발송」을 되살리면 화면이 다시 거짓말을 한다.
     *
     * 기능 테스트로는 못 잡는다. 자동 발송이 살아 있어도 **알림은 정상적으로 나가기 때문**이다.
     * 문제는 그게 화면에 안 보인다는 것이고, 그건 코드 모양으로만 검사할 수 있다.
     */
    public function test_no_alimtalk_sender_reads_the_salesman_phone_directly(): void
    {
        $hits = [];
        foreach (['app/Console/Commands', 'app/Http/Controllers', 'app/Models', 'resources/views/livewire'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($it as $f) {
                if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                if (! str_contains($src, 'BizmAlimtalkService')) {
                    continue;
                }
                if (preg_match('/salesman\?->phone|forVehicleSalesman/', $src)) {
                    $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname());
                }
            }
        }

        $this->assertSame([], $hits,
            '알림톡을 보내는 파일이 담당 영업 번호를 직접 읽고 있다 — 체크박스 밖의 숨은 발송이다. '
            .'AlimtalkRecipients::scopedFor() 를 쓸 것: '.implode(', ', $hits));
    }

    /** 스코프형으로 옮긴 알림은 역할 선택형이어야 안내 화면에 체크박스가 뜬다. */
    public function test_scoped_codes_are_all_role_selectable(): void
    {
        foreach ([
            'erp_vehicle_new', 'erp_purchase_unpaid', 'erp_sale_unpaid', 'erp_settle_pending',
            'erp_eta_balance_due', 'erp_shipping_due', 'erp_deposit_cash_due',
            'erp_pickup_reminder', 'erp_purchase_paid_v2',
        ] as $code) {
            $this->assertArrayHasKey($code, AlimtalkTemplates::TEMPLATES, "{$code} 템플릿 없음");
            $this->assertTrue(AlimtalkRecipients::isBroadcast($code),
                "{$code} 가 역할 선택형이 아니다 — 안내 화면에 체크박스가 안 뜨고 켤 방법이 없다");
        }
    }

    /** 배포 직후 조용해지면 안 된다 — 구 자동 발송 3종은 기본값에 '영업'이 있어야 동작이 보존된다. */
    public function test_defaults_preserve_the_old_automatic_sends(): void
    {
        foreach (['erp_pickup_reminder', 'erp_deposit_cash_due', 'erp_purchase_paid_v2'] as $code) {
            $this->assertContains('영업', AlimtalkRecipients::DEFAULT_ROLES[$code],
                "{$code} 는 구 코드가 담당 영업에게 자동 발송했다 — 기본값에서 빼면 배포 직후 조용히 끊긴다");
        }
    }
}
