<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 알림톡 자동발송 트리거 배선 — 수신자 resolver + cron 커맨드 + 이벤트 훅.
 * BizM 발송은 Http::fake 로 가로챔. 게이트 off 면 skipped, on 이면 sent 로그.
 */
class AlimtalkTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'MSG-TEST'], 'message' => 'K000']], 200)]);
    }

    /** 알림톡 계정·게이트 켜기 (현재 회사 set). 전달한 코드의 tmplId 세팅. */
    private function enableAlimtalk(array $codes): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_itemlist_{$set}"], ['value' => '1', 'type' => 'boolean']);   // 아이템리스트형 게이트 on
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        foreach ($codes as $c) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$c}_{$set}"], ['value' => 'T_'.$c, 'type' => 'string']);
        }
    }

    private function admin(string $phone): User
    {
        // 운영 최고관리자처럼 role='관리'도 겸함 — 그래도 관리 알림엔 안 잡혀야(대표=요약만).
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    private function manager(string $phone): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    /**
     * 팀이 있는 [관리] 를 만든다 — 2026-08-24 개편 후 **배정 0명인 [관리]는 아무것도 안 받는다**
     * (체크 = 받을지 / 역할 = 받을 범위). 그게 jin 이 요청한 규칙이라, 테스트도 팀을 만들어 준다.
     *
     * @return array{0:User,1:Salesman}
     */
    private function teamManager(string $phone): array
    {
        $mgr = $this->manager($phone);
        $salesUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'manager_user_id' => $mgr->id,
            'phone' => null, 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['name' => '팀영업', 'is_active' => true, 'user_id' => $salesUser->id]);

        return [$mgr, $sm];
    }

    public function test_recipients_resolver_by_role(): void
    {
        $this->admin('010-1111-0000');
        User::factory()->create(['permission' => 'super', 'role' => '관리', 'phone' => '010-9999-0000', 'email_verified_at' => now()]);  // super 제외(role='관리' 겸해도)
        $this->manager('010-2222-0000');
        User::factory()->create(['permission' => 'manager', 'phone' => '010-4444-0000', 'email_verified_at' => now()]);  // 업무관리자
        User::factory()->create(['permission' => 'user', 'role' => '영업', 'phone' => '010-3333-0000', 'email_verified_at' => now()]);

        $this->assertSame(['010-1111-0000'], AlimtalkRecipients::admins(), '대표=admin만(super 제외)');
        // 대표(admin)·super 가 role='관리'를 겸해도 관리 알림엔 제외 — 관리 6종은 순수 관리/업무관리자만.
        $this->assertEqualsCanonicalizing(['010-2222-0000', '010-4444-0000'], AlimtalkRecipients::managers(), '관리 role + 업무관리자(manager), 대표·super 제외');
        $this->assertSame(['010-4444-0000'], AlimtalkRecipients::payoutApprovers(2), '배치 level2 = 업무관리자');
        $this->assertSame(['010-1111-0000'], AlimtalkRecipients::payoutApprovers(3), '배치 level3 = 대표');
    }

    public function test_recipients_override_setting_wins(): void
    {
        $this->admin('010-1111-0000');
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_recipients_admin_{$set}"], ['value' => '010-7777-7777, 010-8888-8888', 'type' => 'string']);

        $this->assertSame(['010-7777-7777', '010-8888-8888'], AlimtalkRecipients::admins());
    }

    public function test_daily_summary_sends_to_admins_when_enabled(): void
    {
        $this->admin('010-1111-0000');
        $this->enableAlimtalk(['erp_daily_summary']);

        $this->artisan('alimtalk:daily-summary')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_daily_summary')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('01011110000', $log->phone);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bizmsg.kr')
            && $req->data()[0]['tmplId'] === 'T_erp_daily_summary');
    }

    public function test_gate_off_skips_send(): void
    {
        $this->admin('010-1111-0000');   // 수신자는 있으나 게이트 미설정

        $this->artisan('alimtalk:daily-summary')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_daily_summary')->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->status);
        Http::assertNothingSent();
    }

    public function test_pickup_sends_only_to_vehicle_salesman(): void
    {
        $this->enableAlimtalk(['erp_pickup_reminder']);
        $sm = Salesman::create(['name' => '김영업', 'phone' => '010-5555-0000', 'is_active' => true]);
        // 매입일 3일 전 + 매입 미완납(지급 0)
        Vehicle::create([
            'vehicle_number' => '11가1234', 'sales_channel' => 'export',
            'purchase_price' => 5_000_000, 'purchase_date' => now()->subDays(3)->toDateString(),
            'salesman_id' => $sm->id,
        ]);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_pickup_reminder')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('01055550000', $log->phone);
    }

    public function test_pickup_elapsed_days_is_positive(): void
    {
        // Carbon 3 의 diffInDays 는 부호 있는 값이라 방향을 뒤집으면 음수가 나간다.
        // 실제로 "-42일 경과" 로 295건 발송됐었다(2026-07-28 발견).
        $this->enableAlimtalk(['erp_pickup_reminder']);
        $sm = Salesman::create(['name' => '최영업', 'phone' => '010-7777-0000', 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => '33다3456', 'sales_channel' => 'export',
            'purchase_price' => 5_000_000, 'purchase_date' => now()->subDays(42)->toDateString(),
            'salesman_id' => $sm->id,
        ]);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        Http::assertSent(function ($request) {
            $highlight = $request->data()[0]['items']['itemHighlight']['description'] ?? '';

            return $highlight === '42일 경과 · 미완납';
        });
    }

    public function test_pickup_skips_when_purchase_paid(): void
    {
        $this->enableAlimtalk(['erp_pickup_reminder']);
        $sm = Salesman::create(['name' => '박영업', 'phone' => '010-6666-0000', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2345', 'sales_channel' => 'export',
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(3)->toDateString(),
            'salesman_id' => $sm->id,
        ]);
        $v->purchaseBalancePayments()->create(['amount' => 1_000_000, 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);

        $this->artisan('alimtalk:pickup')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_pickup_reminder')->count());
    }

    // ── 말소 재촉 (erp_deregistration_reminder, jin 2026-09-07) ──
    //   대상 = 매입 완납 +2일 & is_deregistered=false & 컨테이너번호·수출신고번호 없음. 목록형 1통.

    /** 매입 완납 차량(잔금 전액 확정) 하나 — 말소 재촉 대상의 기본 재료. */
    private function paidVehicle(Salesman $sm, string $plate, int $daysAgo, array $extra = []): Vehicle
    {
        // ⚠️ 바이어가 있어야 대상이다 — 바이어 없는 일반재고(투기매입)는 알림에서 제외한다(jin 2026-09-07).
        $buyer = Buyer::firstOrCreate(['name' => '말소테스트바이어']);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => $plate, 'sales_channel' => 'export',
            'purchase_price' => 3_000_000, 'purchase_date' => now()->subDays($daysAgo + 1)->toDateString(),
            'purchase_from' => '○○모터스', 'salesman_id' => $sm->id, 'is_deregistered' => false,
            'buyer_id' => $buyer->id,
        ], $extra));
        // 완납일 = 확정 매입잔금의 마지막 지급일(= Vehicle::warehouse_in_date).
        $v->purchaseBalancePayments()->create([
            'amount' => 3_000_000, 'payment_date' => now()->subDays($daysAgo)->toDateString(), 'confirmed_at' => now(),
        ]);

        return $v->fresh();
    }

    public function test_deregistration_sends_list_to_vehicle_salesman(): void
    {
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '김영업', 'phone' => '010-5555-1111', 'is_active' => true]);
        $this->paidVehicle($sm, '11가1234', 3);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $log = AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('01055551111', $log->phone);
        Http::assertSent(fn ($req) => str_contains($req->data()[0]['msg'] ?? '', '11가1234')
            && str_contains($req->data()[0]['msg'] ?? '', '완납 3일 경과'));
    }

    public function test_deregistration_skips_within_grace_days(): void
    {
        // 완납 1일차 = 유예 안. 그 사람 차가 그것뿐이면 통째로 skip(빈 목록을 보내지 않는다).
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '이영업', 'phone' => '010-5555-2222', 'is_active' => true]);
        $this->paidVehicle($sm, '22나2345', 1);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count());
    }

    public function test_deregistration_ignores_document_only_gap(): void
    {
        // 🎯 jin 2026-09-07 결정 박제 — 「말소는 했고 말소등록증 파일만 없음」은 **대상이 아니다**.
        //    scopeAction('deregistration_needed') 는 그것도 잡지만(실측 57대 중 41대), 본문이
        //    "말소 처리해 주세요" 라 그쪽에 보내면 거짓 재촉이 된다.
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '박영업', 'phone' => '010-5555-3333', 'is_active' => true]);
        $this->paidVehicle($sm, '33다3456', 5, ['is_deregistered' => true]);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count());
    }

    public static function shippedMarkerProvider(): array
    {
        // 컨테이너번호·수출신고번호 어느 쪽이든 있으면 그만 보낸다(jin). 빈 문자열은 «없음» 취급.
        return [
            '컨테이너번호' => ['container_number', 'ABCD1234567'],
            '수출신고번호' => ['export_declaration_number', '12345-67-890123X'],
        ];
    }

    #[DataProvider('shippedMarkerProvider')]
    public function test_deregistration_stops_once_shipping_marker_exists(string $column, string $value): void
    {
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '최영업', 'phone' => '010-5555-4444', 'is_active' => true]);
        $this->paidVehicle($sm, '44라4567', 5, [$column => $value]);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count());
    }

    public function test_deregistration_skips_general_stock_without_buyer(): void
    {
        // 바이어 미정 = 일반재고(투기매입). 팔릴 때까지 말소를 서두를 이유가 없다(jin 2026-09-07).
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '재고영업', 'phone' => '010-5555-7777', 'is_active' => true]);
        $v = $this->paidVehicle($sm, '88아8888', 5);
        $v->forceFill(['buyer_id' => null])->saveQuietly();

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count());
    }

    public function test_deregistration_bundles_multiple_vehicles_into_one_message(): void
    {
        // 차량 1대 = 1통이면 실측 최대 13통/일이 된다 → 사람당 1통 목록형(jin 2026-09-07).
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '한영업', 'phone' => '010-5555-5555', 'is_active' => true]);
        $this->paidVehicle($sm, '55마5678', 3);
        $this->paidVehicle($sm, '66바6789', 9);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count());
        Http::assertSent(fn ($req) => str_contains($req->data()[0]['msg'] ?? '', '55마5678')
            && str_contains($req->data()[0]['msg'] ?? '', '66바6789'));
    }

    public function test_deregistration_counts_from_the_last_confirmed_balance_payment(): void
    {
        // 매입잔금은 2~3회로 쪼개 지급되는 일이 흔하다(운영 실측 239대 중 24대).
        // 첫 지급일을 기준으로 삼으면 경과일이 부풀려져 «완납 20일 경과» 같은 거짓이 나간다.
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $sm = Salesman::create(['name' => '서영업', 'phone' => '010-5555-6666', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '77사7890', 'sales_channel' => 'export',
            'purchase_price' => 4_000_000, 'purchase_date' => now()->subDays(30)->toDateString(),
            'purchase_from' => '△△오토', 'salesman_id' => $sm->id, 'is_deregistered' => false,
            'buyer_id' => Buyer::firstOrCreate(['name' => '말소테스트바이어'])->id,
        ]);
        $v->purchaseBalancePayments()->create(['amount' => 1_000_000, 'payment_date' => now()->subDays(20)->toDateString(), 'confirmed_at' => now()]);
        $v->purchaseBalancePayments()->create(['amount' => 3_000_000, 'payment_date' => now()->subDays(4)->toDateString(), 'confirmed_at' => now()]);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->data()[0]['msg'] ?? '', '완납 4일 경과'));
    }

    // ── 단계별 확대 (jin 2026-09-07) — 영업·관리 D+2 → 업무관리자 D+3 → 최고관리자 D+4, 누적 ──

    /** 그 알림의 수신 역할을 명시 저장 (미설정 시 DEFAULT_ROLES 로 떨어지므로 테스트는 늘 명시한다). */
    private function setRoles(string $code, array $groups): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_roles_{$code}_{$set}"],
            ['value' => implode(',', $groups), 'type' => 'string']);
    }

    public function test_escalation_holds_back_later_tiers_until_their_day(): void
    {
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $this->setRoles('erp_deregistration_reminder', ['영업', 'manager', 'admin']);
        $sm = Salesman::create(['name' => '단계영업', 'phone' => '010-8888-0001', 'is_active' => true]);
        $mgr = User::factory()->create(['permission' => 'manager',
            'phone' => '010-8888-0002', 'email_verified_at' => now()]);
        $adm = User::factory()->create(['permission' => 'admin', 'phone' => '010-8888-0003', 'email_verified_at' => now()]);
        $this->paidVehicle($sm, '11가1111', 2);   // D+2 — 영업만

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        $phones = AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->pluck('phone')->all();
        $this->assertSame(['01088880001'], $phones, 'D+2 에는 영업만 받는다');
        $this->assertNotNull($mgr);
        $this->assertNotNull($adm);
    }

    public function test_escalation_adds_manager_on_day_three_and_admin_on_day_four(): void
    {
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $this->setRoles('erp_deregistration_reminder', ['영업', 'manager', 'admin']);
        $sm = Salesman::create(['name' => '단계영업', 'phone' => '010-8888-0001', 'is_active' => true]);
        User::factory()->create(['permission' => 'manager',
            'phone' => '010-8888-0002', 'email_verified_at' => now()]);
        User::factory()->create(['permission' => 'admin', 'phone' => '010-8888-0003', 'email_verified_at' => now()]);
        $this->paidVehicle($sm, '22나2222', 3);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();
        $day3 = AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->pluck('phone')->sort()->values()->all();
        $this->assertSame(['01088880001', '01088880002'], $day3, 'D+3 에는 업무관리자까지');

        AlimtalkLog::query()->delete();
        Vehicle::query()->update(['vehicle_number' => '33다3333']);
        $this->paidVehicle($sm, '44라4444', 4);   // D+4 한 대 추가

        $this->artisan('alimtalk:deregistration')->assertSuccessful();
        $day4 = AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->pluck('phone')->sort()->values()->all();
        $this->assertSame(['01088880001', '01088880002', '01088880003'], $day4, 'D+4 에는 최고관리자까지');
    }

    // ℹ️ 「한 사람이 두 티어에 걸려 두 통」은 이 구조에서 안 생긴다 — 역할 그룹이 permission 으로 갈려
    //    (admin / manager / user+role) **한 사람은 정확히 한 그룹**이다. 최고관리자가 role='관리' 를
    //    겸해도 [관리] 그룹은 permission='user' 만 잡는다(AlimtalkRecipients::groupQuery).
    //    scopedForTiered 의 전화번호 합집합은 그래도 남겨 둔다 — ERP 계정 없는 영업담당자가
    //    같은 번호를 쓰는 경우처럼 번호가 겹칠 여지는 있다.

    public function test_later_tier_does_not_receive_vehicles_below_its_day(): void
    {
        // 겸직이 아닌 순수 최고관리자는 D+4 이상만 본다 — D+2 짜리는 안 담긴다.
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $this->setRoles('erp_deregistration_reminder', ['영업', 'admin']);
        $sm = Salesman::create(['name' => '단계영업', 'phone' => '010-8888-0001', 'is_active' => true]);
        User::factory()->create(['permission' => 'admin', 'phone' => '010-8888-0003', 'email_verified_at' => now()]);
        $this->paidVehicle($sm, '55마5555', 2);
        $this->paidVehicle($sm, '66바6666', 9);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();

        Http::assertSent(function ($req) {
            $d = $req->data()[0];
            if (($d['phn'] ?? '') !== '01088880003') {
                return false;
            }

            return str_contains($d['msg'] ?? '', '66바6666') && ! str_contains($d['msg'] ?? '', '55마5555');
        });
    }

    public function test_escalation_days_are_configurable_per_company(): void
    {
        // 🚫 일수를 코드에 박으면 화면에 안 보이는 규칙이 된다(§8 #60) — 설정으로 바뀌어야 한다.
        $this->enableAlimtalk(['erp_deregistration_reminder']);
        $this->setRoles('erp_deregistration_reminder', ['영업']);
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_escalate_erp_deregistration_reminder_영업_{$set}"],
            ['value' => '10', 'type' => 'string']);
        $sm = Salesman::create(['name' => '단계영업', 'phone' => '010-8888-0001', 'is_active' => true]);
        $this->paidVehicle($sm, '77사7777', 5);

        $this->artisan('alimtalk:deregistration')->assertSuccessful();
        $this->assertSame(0, AlimtalkLog::where('template_code', 'erp_deregistration_reminder')->count(),
            'D+10 으로 올렸으면 5일차는 아직 안 간다');

        Setting::updateOrCreate(['key' => "alimtalk_escalate_erp_deregistration_reminder_영업_{$set}"],
            ['value' => '', 'type' => 'string']);
        $this->assertSame(2, AlimtalkRecipients::escalationDays('erp_deregistration_reminder', '영업'),
            '비우면 기본값으로 돌아간다');
    }

    public function test_settle_pending_hook_notifies_managers_on_created(): void
    {
        [, $sm] = $this->teamManager('010-2222-0000');
        $this->enableAlimtalk(['erp_settle_pending']);
        $v = Vehicle::create(['vehicle_number' => '33다3456', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);

        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'per_unit',
            'settlement_status' => 'pending',
        ]);

        $log = AlimtalkLog::where('template_code', 'erp_settle_pending')->first();
        $this->assertNotNull($log, '정산 pending 생성 시 관리 알림');
        $this->assertSame('sent', $log->status);
        $this->assertSame('01022220000', $log->phone);
    }

    public function test_sale_unpaid_sends_one_list_message_not_per_vehicle(): void
    {
        [, $sm] = $this->teamManager('010-2222-0000');
        $this->enableAlimtalk(['erp_sale_unpaid']);
        $buyer = Buyer::create(['name' => 'DONI', 'is_active' => true]);
        foreach (['11가1111', '22나2222'] as $no) {
            Vehicle::create([
                'vehicle_number' => $no, 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
                'salesman_id' => $sm->id,
                'sale_price' => 10_000_000, 'sale_date' => now()->subDays(30)->toDateString(),
                'currency' => 'KRW', 'exchange_rate' => 1,
            ]);
        }

        $this->artisan('alimtalk:sale-unpaid')->assertSuccessful();

        $logs = AlimtalkLog::where('template_code', 'erp_sale_unpaid')->get();
        $this->assertCount(1, $logs, '차량 2대여도 목록형 1건(건건이 아님)');
        $this->assertStringContainsString('11가1111', $logs[0]->message);
        $this->assertStringContainsString('22나2222', $logs[0]->message);
        $this->assertStringContainsString('채권관리', $logs[0]->message, 'ERP 위치 안내 포함');
    }

    public function test_all_trigger_template_codes_exist(): void
    {
        // 배선한 코드가 모두 템플릿 단일출처에 존재해야(오타 방지).
        foreach ([
            'erp_daily_summary', 'erp_weekly_summary', 'erp_monthly_closing',
            'erp_purchase_unpaid', 'erp_sale_unpaid', 'erp_eta_balance_due',
            'erp_shipping_due', 'erp_pickup_reminder', 'erp_vehicle_new', 'erp_settle_pending',
            'erp_payout_request', 'erp_payout_done', 'erp_payout_rejected',
        ] as $code) {
            $this->assertArrayHasKey($code, AlimtalkTemplates::TEMPLATES, $code.' 템플릿 존재');
        }
    }

    /** 월배치 정산지급 — 제출→계단 전진→최종 승인마다 상대측에 알림톡. */
    public function test_payout_batch_flow_notifies_each_party(): void
    {
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '김제출', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $mgr = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $adm = User::factory()->create(['permission' => 'admin', 'phone' => '010-3000-0000', 'email_verified_at' => now()]);
        $this->enableAlimtalk(['erp_payout_request', 'erp_payout_done']);

        $sm = Salesman::create(['name' => '제출영업', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '99가9999', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-06-15 10:00:00',
        ]);

        // 제출 → level2(업무관리자)에게 요청.
        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-06');
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_request')->where('phone', '01020000000')->where('status', 'sent')->count(), '제출→업무관리자 요청');

        // 업무관리자 승인 → level3(대표)에게 요청.
        $batch->approveBy($mgr);
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_request')->where('phone', '01030000000')->where('status', 'sent')->count(), '전진→대표 요청');

        // 대표 최종 승인 → 제출자에게 완료.
        $batch->approveBy($adm);
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_payout_done')->where('phone', '01010000000')->where('status', 'sent')->count(), '최종→제출자 완료');
        $this->assertSame('approved', $batch->fresh()->status);
    }

    /** 반려 → 제출자에게 사유 포함 알림톡. */
    public function test_payout_batch_reject_notifies_submitter(): void
    {
        $submitter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '박제출', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $mgr = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $this->enableAlimtalk(['erp_payout_request', 'erp_payout_rejected']);

        $sm = Salesman::create(['name' => '반려영업', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => '88나8888', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-06-15 10:00:00',
        ]);

        $batch = SettlementPayoutBatch::submitForMonth($submitter, '2026-06');
        $batch->rejectBy($mgr, '금액 오류');

        $log = AlimtalkLog::where('template_code', 'erp_payout_rejected')->first();
        $this->assertNotNull($log);
        $this->assertSame('01010000000', $log->phone, '반려는 제출자에게');
        // 사유는 아이템리스트형 카드(items)로 이동 — 발송 payload 로 확인(body 아님).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bizmsg.kr')
            && str_contains(json_encode($req->data()[0], JSON_UNESCAPED_UNICODE), '금액 오류'));
        $this->assertSame('rejected', $batch->fresh()->status);
    }
}
