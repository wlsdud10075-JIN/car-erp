<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사내 업무 도우미 (로컬 LLM 챗봇) — jin 2026-07-24.
 *   라우팅=키워드(결정적), B=DB 조회(숫자 서버삽입, 대시보드 정합), 권한 2단계, 감사, A=RAG(fake).
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function seedReceivables(): void
    {
        $sA = Salesman::create(['name' => '김영업', 'is_active' => true]);
        $sB = Salesman::create(['name' => '이영업', 'is_active' => true]);
        $bX = Buyer::create(['name' => 'Buyer-X', 'is_active' => true]);
        $bY = Buyer::create(['name' => 'Buyer-Y', 'is_active' => true]);

        // 출고됨(warehouse_out_date) → 선적후, grace 아님 → 채권 집계 포함
        $mk = function ($sm, $by, $price, $out) {
            Vehicle::create([
                'vehicle_number' => '11가'.rand(1000, 9999),
                'sales_channel' => 'export', 'salesman_id' => $sm, 'buyer_id' => $by,
                'sale_date' => '2026-05-01', 'sale_price' => $price, 'currency' => 'KRW', 'exchange_rate' => 1,
                'warehouse_out_date' => $out,
            ]);
        };
        $mk($sA->id, $bX->id, 20_000_000, '2026-06-01');  // 김영업 / Buyer-X
        $mk($sA->id, $bY->id, 10_000_000, '2026-06-01');  // 김영업 / Buyer-Y
        $mk($sB->id, $bX->id, 5_000_000, '2026-06-01');   // 이영업 / Buyer-X
    }

    public function test_classify_routes_intents(): void
    {
        $svc = app(AssistantService::class);
        $this->assertSame('capital_status', $svc->classify('회사 자금 현황 알려줘'));
        $this->assertSame('capital_status', $svc->classify('이번 분기 순이익 얼마야'));
        $this->assertSame('break_even', $svc->classify('지금 손익분기 넘었어?'));
        $this->assertSame('sales_by_salesman', $svc->classify('이번 달 인원별 매출 현황'));
        $this->assertSame('receivable_by_salesman', $svc->classify('담당자별 미수 현황'));
        $this->assertSame('receivable_by_buyer', $svc->classify('바이어별 미수금 보여줘'));
        $this->assertSame('receivable_summary', $svc->classify('채권 요약'));
        $this->assertSame('system_guide', $svc->classify('알림톡 로그 어디서 봐?'));
        $this->assertSame('guide', $svc->classify('정산은 누가 확정해?'));
    }

    public function test_receivable_by_salesman_matches_ledger(): void
    {
        $this->seedReceivables();
        $svc = app(AssistantService::class);
        $res = $svc->ask('담당자별 미수 현황', $this->admin());

        $this->assertSame('receivable', $res['kind']);
        // 김영업 = 20M+10M = 30,000,000원 (1위), 이영업 = 5,000,000원
        $this->assertStringContainsString('김영업', $res['answer']);
        $this->assertStringContainsString('30,000,000원', $res['answer']);
        $this->assertStringContainsString('이영업', $res['answer']);
        $this->assertStringContainsString('5,000,000원', $res['answer']);
    }

    public function test_receivable_by_buyer_matches_ledger(): void
    {
        $this->seedReceivables();
        $res = app(AssistantService::class)->ask('바이어별 미수', $this->admin());
        // Buyer-X = 20M+5M = 25,000,000원, Buyer-Y = 10,000,000원
        $this->assertStringContainsString('Buyer-X', $res['answer']);
        $this->assertStringContainsString('25,000,000원', $res['answer']);
    }

    public function test_receivable_summary_totals(): void
    {
        $this->seedReceivables();
        $res = app(AssistantService::class)->ask('채권 요약', $this->admin());
        // 총 미수 35,000,000, 전부 선적후(출고일 있음)
        $this->assertStringContainsString('35,000,000원', $res['answer']);
        $this->assertStringContainsString('선적후 미수', $res['answer']);
    }

    public function test_capital_denied_for_non_admin(): void
    {
        // 재무 = canUseAssistant 이지만 canViewCapital 아님 → 자금 질의 거부
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $res = app(AssistantService::class)->ask('회사 자금 현황', $finance);

        $this->assertSame('denied', $res['kind']);
        $this->assertStringContainsString('현재 계정 권한으로 조회할 수 없습니다', $res['answer']);
        // 감사에 denied 기록
        $this->assertTrue(AuditLog::where('action', 'assistant_query')
            ->where('column_name', 'capital_status(denied)')->exists());
    }

    /** 판매일 기준 이번 달 집계 + 전사 스코프(최고관리자). */
    public function test_sales_by_salesman_uses_current_month_for_admin(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00'));
        $this->salesVehicle('김대표실적', '11가7777', 20_000_000);

        $res = app(AssistantService::class)->ask('이번 달 인원별 매출 현황', $this->admin());

        $this->assertSame('sales', $res['kind']);
        $this->assertStringContainsString('전사', $res['answer']);
        $this->assertStringContainsString('김대표실적', $res['answer']);
        $this->assertStringContainsString('20,000,000원', $res['answer']);
    }

    /**
     * 🚩 jin 2026-07-29 — 인원별 매출은 "대표 전용 거부"가 아니라 **스코프**다.
     *   업무관리자 = 전사 / [관리] = 본인이 담당하는 영업만 / 재무·수출통관 = 거부(매출은 관리 라인만).
     */
    public function test_sales_by_salesman_is_scoped_not_denied_for_management_line(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00'));
        $mine = $this->salesVehicle('내담당영업', '11가1111', 10_000_000);
        $this->salesVehicle('남의영업', '11가2222', 90_000_000);

        // 업무관리자 — 전사(둘 다 보인다)
        $manager = User::factory()->create(['permission' => 'manager', 'email_verified_at' => now()]);
        $managerRes = app(AssistantService::class)->ask('이번 달 인원별 매출 현황', $manager);
        $this->assertSame('sales', $managerRes['kind']);
        $this->assertStringContainsString('전사', $managerRes['answer']);
        $this->assertStringContainsString('내담당영업', $managerRes['answer']);
        $this->assertStringContainsString('남의영업', $managerRes['answer']);

        // [관리] — 본인이 담당하는 영업만. 남의 담당은 이름도 금액도 새면 안 된다.
        $govern = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $mine->user->managers()->sync([$govern->id]);
        $governRes = app(AssistantService::class)->ask('이번 달 인원별 매출 현황', $govern);
        $this->assertSame('sales', $governRes['kind']);
        $this->assertStringContainsString('담당 영업', $governRes['answer']);
        $this->assertStringContainsString('내담당영업', $governRes['answer']);
        $this->assertStringNotContainsString('남의영업', $governRes['answer'], '[관리] 에 타 담당 매출 노출');
        $this->assertStringNotContainsString('90,000,000', $governRes['answer']);
    }

    /** 재무·수출통관은 담당 영업이 없다 — 0원이 아니라 거부여야 한다(0원은 버그로 읽힌다). */
    public function test_sales_by_salesman_denied_outside_management_line(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00'));
        $this->salesVehicle('아무영업', '11가3333', 5_000_000);

        foreach (['재무', '수출통관'] as $role) {
            $u = User::factory()->create(['permission' => 'user', 'role' => $role, 'email_verified_at' => now()]);
            $res = app(AssistantService::class)->ask('이번 달 인원별 매출 현황', $u);
            $this->assertSame('denied', $res['kind'], "{$role} 에 인원별 매출 노출");
            $this->assertStringNotContainsString('아무영업', $res['answer']);
        }
        $this->assertDatabaseHas('audit_logs', ['column_name' => 'sales_by_salesman(denied)']);
    }

    /** 담당 영업 0명인 [관리] — 전량 스캔으로 빠지지 않고 빈 결과여야 한다. */
    public function test_sales_by_salesman_empty_scope_returns_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00'));
        $this->salesVehicle('남의영업만', '11가4444', 7_000_000);

        $govern = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $res = app(AssistantService::class)->ask('이번 달 인원별 매출 현황', $govern);

        $this->assertSame('sales', $res['kind']);
        $this->assertStringNotContainsString('남의영업만', $res['answer']);
    }

    /** 매출 집계용 차량 1대 + 그 영업의 user 를 함께 만든다. */
    private function salesVehicle(string $name, string $plate, int $salePrice): Salesman
    {
        $user = User::factory()->create([
            'name' => $name, 'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
        $salesman = Salesman::create(['user_id' => $user->id, 'name' => $name, 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => $plate,
            'sales_channel' => 'export',
            'salesman_id' => $salesman->id,
            'sale_date' => '2026-07-10',
            'sale_price' => $salePrice,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);

        return $salesman;
    }

    public function test_query_is_audited(): void
    {
        $this->seedReceivables();
        $admin = $this->admin();
        app(AssistantService::class)->ask('채권 요약', $admin);

        $log = AuditLog::where('action', 'assistant_query')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('receivable_summary', $log->column_name);
        $this->assertSame('채권 요약', $log->new_value);
    }

    public function test_guide_uses_rag_with_fake_ollama(): void
    {
        // 임시 색인 + fake Ollama (HTTP 미발생)
        $idx = tempnam(sys_get_temp_dir(), 'idx').'.json';
        file_put_contents($idx, json_encode([
            ['source' => '정산 가이드', 'text' => '정산은 재무가 확정한다.', 'embedding' => [1.0, 0.0]],
            ['source' => '기타', 'text' => '무관한 내용', 'embedding' => [0.0, 1.0]],
        ]));
        config(['assistant.index_path' => $idx, 'assistant.index_scope' => '']);

        $this->app->bind(OllamaClient::class, fn () => new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake', 1);
            }

            public function embed(string $m, string $t): array
            {
                return [1.0, 0.0];
            }  // 정산 가이드와 일치

            public function chat(string $m, string $s, string $u): string
            {
                return '정산은 재무가 확정합니다.';
            }
        });

        $res = app(AssistantService::class)->ask('정산은 누가 확정해?', $this->admin());
        $this->assertSame('guide', $res['kind']);
        $this->assertStringContainsString('재무', $res['answer']);
        $this->assertContains('정산 가이드', collect($res['sources'])->pluck('title')->all());
        @unlink($idx);
    }

    public function test_guide_scope_excludes_out_of_scope_chunks(): void
    {
        // board 청크가 질문에 더 가까워도, ERP 스코프면 검색 대상에서 제외 (jin 2026-07-24)
        $idx = tempnam(sys_get_temp_dir(), 'idx').'.json';
        file_put_contents($idx, json_encode([
            ['source' => '사내 업무 가이드 › 🛒 매입보드 (BOARD) › 선적', 'text' => 'board 선적 계획 탭에서 동기화', 'embedding' => [1.0, 0.0]],
            ['source' => '사내 업무 가이드 › 🏢 ERP (car-erp) › 수출통관', 'text' => 'ERP 선적요청 절차', 'embedding' => [0.9, 0.1]],
        ]));
        config(['assistant.index_path' => $idx, 'assistant.index_scope' => 'ERP (car-erp)']);

        $this->app->bind(OllamaClient::class, fn () => new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake', 1);
            }

            public function embed(string $m, string $t): array
            {
                return [1.0, 0.0];
            }  // board 청크와 정확 일치하지만 스코프에서 제외돼야

            public function chat(string $m, string $s, string $u): string
            {
                return '(답변)';
            }
        });

        $res = app(AssistantService::class)->ask('선적요청은 어떻게 해?', $this->admin());
        $titles = collect($res['sources'])->pluck('title')->all();
        $this->assertContains('사내 업무 가이드 › 🏢 ERP (car-erp) › 수출통관', $titles, 'ERP 청크만 검색');
        $this->assertNotContains('사내 업무 가이드 › 🛒 매입보드 (BOARD) › 선적', $titles, 'board 청크 제외');
        @unlink($idx);
    }

    public function test_guide_filters_chunks_by_logged_in_user_audience_before_rag(): void
    {
        $idx = tempnam(sys_get_temp_dir(), 'idx').'.json';
        file_put_contents($idx, json_encode([
            ['source' => 'ERP 공통', 'text' => '실무자 공개 절차', 'audience' => 'staff', 'embedding' => [0.0, 1.0]],
            ['source' => 'ERP 대표', 'text' => '대표 전용 청산가치', 'audience' => 'executive', 'embedding' => [1.0, 0.0]],
            ['source' => 'ERP 시스템', 'text' => '시스템관리자 전용 설정', 'audience' => 'system', 'embedding' => [1.0, 0.0]],
            ['source' => 'ERP 잘못된등급', 'text' => '잘못된 접근등급', 'audience' => 'unknown', 'embedding' => [1.0, 0.0]],
            ['source' => 'ERP 누락', 'text' => '등급 누락 민감자료', 'embedding' => [1.0, 0.0]],
        ], JSON_UNESCAPED_UNICODE));
        config(['assistant.index_path' => $idx, 'assistant.index_scope' => '', 'assistant.rag_topk' => 3]);

        $this->app->bind(OllamaClient::class, fn () => new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake', 1);
            }

            public function embed(string $m, string $t): array
            {
                return [1.0, 0.0];
            }

            public function chat(string $m, string $s, string $u): string
            {
                return $u;
            }
        });

        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $staffResult = app(AssistantService::class)->ask('회사 운영 절차 알려줘', $finance);
        $this->assertStringContainsString('실무자 공개 절차', $staffResult['answer']);
        $this->assertStringNotContainsString('대표 전용 청산가치', $staffResult['answer']);
        $this->assertStringNotContainsString('시스템관리자 전용 설정', $staffResult['answer']);
        $this->assertStringNotContainsString('잘못된 접근등급', $staffResult['answer']);
        $this->assertStringNotContainsString('등급 누락 민감자료', $staffResult['answer']);

        $executiveResult = app(AssistantService::class)->ask('회사 운영 절차 알려줘', $this->admin());
        $this->assertStringContainsString('실무자 공개 절차', $executiveResult['answer']);
        $this->assertStringContainsString('대표 전용 청산가치', $executiveResult['answer']);
        $this->assertStringNotContainsString('시스템관리자 전용 설정', $executiveResult['answer']);
        $this->assertStringNotContainsString('잘못된 접근등급', $executiveResult['answer']);
        $this->assertStringNotContainsString('등급 누락 민감자료', $executiveResult['answer']);
        @unlink($idx);
    }

    public function test_system_guide_is_super_only(): void
    {
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $res = app(AssistantService::class)->ask('알림톡 로그 어디서 봐?', $finance);

        $this->assertSame('denied', $res['kind']);
        $this->assertDatabaseHas('audit_logs', ['column_name' => 'system_guide(denied)']);
    }

    public function test_staff_can_use_widget_but_finance_query_is_denied(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        config(['assistant.staff_enabled' => false]);
        Volt::actingAs($sales)->test('assistant.widget')
            ->set('q', '전체 미수 보여줘')
            ->call('send')
            ->assertStatus(403);

        config(['assistant.staff_enabled' => true]);
        Volt::actingAs($sales)->test('assistant.widget')
            ->set('q', '전체 미수 보여줘')
            ->call('send')
            ->assertStatus(200)
            ->assertSet('messages.1.text', '채권관리 요약 정보는 현재 계정 권한으로 조회할 수 없습니다.');

        $this->assertSame(['staff'], $sales->assistantAudiences());
    }

    public function test_super_toggles_assistant_enabled_setting(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        Volt::actingAs($super)->test('admin.settings')
            ->set('assistantEnabled', true)
            ->assertHasNoErrors();
        $this->assertTrue((bool) Setting::get('assistant_enabled'));
    }

    public function test_widget_layout_gated_by_env_and_setting(): void
    {
        config(['assistant.enabled' => true]);
        $admin = $this->admin();

        // 기능설정 토글 off → 위젯 미노출
        Setting::updateOrCreate(['key' => 'assistant_enabled'], ['value' => '0', 'type' => 'boolean']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertDontSee('SSANCAR 업무 도우미', false);

        // 토글 on → 노출
        Setting::updateOrCreate(['key' => 'assistant_enabled'], ['value' => '1', 'type' => 'boolean']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('SSANCAR 업무 도우미', false);
    }

    /**
     * 🚨 **jin 2026-09-09 제보** — 「선적대기 허용 항로인데 미수여도 묶음 착수돼? B/L도 가능해?」를
     * 물었더니 채권 KPI 표(총 미수 640,732,308원 …)가 나왔다. 「미수」라는 낱말 하나 때문에
     * **규칙 질문이 DB 조회로 잡힌** 것이다. 같은 형태로 두 건이 더 있었다(실측).
     *
     * B(DB 조회)는 「얼마」를 답하는 경로다 — 「되나·왜·어디서」를 물으면 가이드로 가야 한다.
     */
    /**
     * 🚨 **운영 로그에서 실제로 나온 오분류** (2026-09-09 실측: heymanerp 49건 + karabaerp 31건).
     *
     * 「미수·채권」 낱말 하나 때문에 규칙 질문이 DB 금액 조회로 갔다. 아래 문장은 **사람이 실제로 그렇게
     * 물은 것**이라 내가 상상한 예문보다 값이 크다 — 특히 「채권담당자」는 역할 이름인데 「채권」+「담당자」로
     * 쪼개져 담당자별 미수 조회로 가 **권한 거부**까지 났다(사용자는 용어 뜻을 물었을 뿐이다).
     *
     * 🚫 의문사만 늘려서는 원리상 못 잡는다 — 「채권담당자」에는 규칙 신호가 아예 없다.
     *    그래서 **화면·기능·역할 이름**을 신호로 쓴다.
     */
    public function test_real_questions_from_the_production_log_are_not_captured_by_the_money_router(): void
    {
        $svc = app(AssistantService::class);

        // karabaerp — 역할 이름이 쪼개져 조회로 가던 것(띄어쓰기 변형까지)
        $this->assertSame('guide', $svc->classify('채권담당자'));
        $this->assertSame('guide', $svc->classify('채권 담당자'));
        $this->assertSame('guide', $svc->classify('채권담당자 역할'));
        $this->assertSame('guide', $svc->classify('채권관리에서 위험도는 판매일 기준 경과일수로 판정되나요'));

        // heymanerp — jin 이 직접 물어본 두 문장
        $this->assertSame('guide', $svc->classify('선적대기 허용 항로인데 미수여도 묶음 착수돼? B/L도 가능해?'));
        $this->assertSame('guide', $svc->classify('선적요청에 미수가 있어도 묶일 수 있는 경우가 뭐뭐있어?'));
        $this->assertSame('guide', $svc->classify('선적요청에서 미수가 있을경우 묶이는 경우가 어떤게 있어?'));

        // 같은 형태로 실측된 나머지
        $this->assertSame('guide', $svc->classify('손익분기가 뭐야?'));
        $this->assertSame('guide', $svc->classify('채권관리 어디서 봐?'));
        $this->assertSame('guide', $svc->classify('미수 있으면 통관 진입이 막히는 조건이 뭐야?'));
        $this->assertSame('guide', $svc->classify('자금 이체는 누가 승인해야 가능해?'));
    }

    /**
     * ⚠️ **금액 신호가 규칙 신호를 이긴다.** 이 순서가 뒤집히면 「얼마나 되나?」 처럼 **되나** 가 들어간
     * 금액 질문이 통째로 가이드로 새어 숫자를 못 받는다. 그리고 **신호가 둘 다 없으면 종전대로 DB 조회**다
     * — 여기서 기본값을 가이드로 바꾸면 잘 쓰던 조회가 조용히 죽는다.
     */
    public function test_number_questions_still_reach_the_database_router(): void
    {
        $svc = app(AssistantService::class);

        // 운영 로그의 **정상** 조회 — 이게 깨지면 잘 쓰던 기능이 죽는다
        $this->assertSame('receivable_summary', $svc->classify('무사백 미수금 리스트 알려줘.'));
        $this->assertSame('receivable_summary', $svc->classify('채권리스트'));
        $this->assertSame('receivable_by_buyer', $svc->classify('바이어별 미수현황은?'));
        $this->assertSame('receivable_by_salesman', $svc->classify('인원별로는 미수금을 알 수 없어?'));
        $this->assertSame('capital_status', $svc->classify('자금현황 알려줘.'));

        $this->assertSame('receivable_summary', $svc->classify('이번달 미수금이 얼마나 되나?'));
        $this->assertSame('receivable_summary', $svc->classify('미수금'));
        $this->assertSame('receivable_by_buyer', $svc->classify('바이어별 미수 알려줘'));
        $this->assertSame('capital_status', $svc->classify('자금 현황 보여줘'));
        $this->assertSame('capital_status', $svc->classify('굴리는 총 자금 얼마'));
        // 🚫 '언제' 를 규칙 신호에 넣지 않는 이유 — 시점을 계산해 주는 DB 질문이 새어 나간다.
        $this->assertSame('break_even', $svc->classify('손익분기 언제 넘어?'));
    }

    /** 규칙 질문이어도 시스템 등급 질문은 `system_guide` 로 남아야 한다 — 아니면 system 청크가 검색에서 빠진다. */
    public function test_a_rule_question_about_system_settings_keeps_the_system_tier(): void
    {
        $svc = app(AssistantService::class);

        $this->assertSame('system_guide', $svc->classify('기능설정에서 알림톡 로그 왜 안 보여?'));
        $this->assertSame('system_guide', $svc->classify('챗봇 색인 어떻게 갱신돼?'));
    }

    /**
     * 근거 중복 제거 (jin 2026-09-09) — 「자동 입력·계산·반영 항목 안내」가 두 페이지에 거의 같은 내용으로
     * 실려 있어 top-3 중 두 자리를 같은 말이 먹고 있었다(운영 실측 cos 0.927 = 색인에서 가장 닮은 쌍).
     */
    public function test_near_duplicate_chunks_do_not_take_two_of_the_top_slots(): void
    {
        $svc = app(AssistantService::class);
        // 1번과 2번이 거의 같은 방향(cos≈0.9997), 3번은 직교.
        $kb = [
            ['source' => 'A', 'embedding' => [1.0, 0.0, 0.0]],
            ['source' => 'A 사본', 'embedding' => [0.999, 0.02, 0.0]],
            ['source' => 'B', 'embedding' => [0.0, 1.0, 0.0]],
        ];
        $scored = [0 => 0.90, 1 => 0.89, 2 => 0.40];   // 내림차순

        $picked = array_keys($svc->selectTopChunks($kb, $scored, 2));
        $this->assertSame([0, 2], $picked, '거의 같은 청크가 두 자리를 먹었다');

        // 점수가 높은 쪽(먼저 온 쪽)을 남긴다.
        $this->assertSame([0], array_keys($svc->selectTopChunks($kb, $scored, 1)));
    }

    /** 서로 다른 내용은 아무리 주제가 가까워도 남긴다 — 임계를 낮추면 멀쩡한 근거가 떨어진다. */
    public function test_merely_related_chunks_are_kept(): void
    {
        $svc = app(AssistantService::class);
        // cos ≈ 0.8 — 운영 색인의 「진행 게이트 두 판」(0.876) 자리. 서로 보완하는 다른 글이다.
        $kb = [
            ['source' => 'A', 'embedding' => [1.0, 0.0]],
            ['source' => 'B', 'embedding' => [0.8, 0.6]],
        ];
        $picked = array_keys($svc->selectTopChunks($kb, [0 => 0.9, 1 => 0.8], 2));
        $this->assertSame([0, 1], $picked);
    }

    /** 임계 1.0 이상 = 중복 제거를 끈 것(전후 대조·긴급 원복용). */
    public function test_dedup_can_be_switched_off(): void
    {
        $svc = app(AssistantService::class);
        $kb = [
            ['source' => 'A', 'embedding' => [1.0, 0.0]],
            ['source' => 'A 사본', 'embedding' => [1.0, 0.0]],
        ];
        $this->assertSame([0], array_keys($svc->selectTopChunks($kb, [0 => 0.9, 1 => 0.9], 2, 0.90)));
        $this->assertSame([0, 1], array_keys($svc->selectTopChunks($kb, [0 => 0.9, 1 => 0.9], 2, 1.0)));
    }

    /**
     * 🔒 **평가 커맨드가 후보 추림을 옮겨 적지 않는지** 정적으로 지킨다.
     *
     * `assistant:eval` 이 스코프·등급 필터를 따로 구현하면 **평가는 찾는데 실제 챗봇은 못 찾는** 상태가
     * 되고, 그때 평가는 「거짓말하는 계기판」이 된다(SKILLS §8 #44). 기능 테스트로는 원리상 못 잡는다 —
     * 양쪽 다 정상 동작하고 숫자만 어긋난다.
     */
    public function test_eval_command_reuses_the_real_candidate_filter(): void
    {
        $src = file_get_contents(base_path('app/Console/Commands/AssistantEval.php'));

        $this->assertStringContainsString('candidateChunks(', $src, '평가가 후보 추림을 직접 구현하고 있다');
        $this->assertStringContainsString('selectTopChunks(', $src, '평가가 중복 제거를 직접 구현하고 있다');
        $this->assertStringNotContainsString('index_scope', $src, '스코프 필터가 평가에 복제됐다');
        $this->assertStringNotContainsString('RAG_AUDIENCES', $src, '등급 필터가 평가에 복제됐다');
    }

    /**
     * 📏 **문맥 예산이 개수보다 먼저 걸린다** (jin 2026-09-09 실측).
     *
     * 청크 길이가 10배까지 달라 개수는 안전한 손잡이가 아니다 — 같은 `topk=4` 인데 문맥이 3,315자와
     * **9,023자**로 갈렸다. 모델 상주 컨텍스트는 4096(실측 `/api/ps`)이고 **넘긴 만큼 조용히 앞부터
     * 잘려 점수 1위 근거가 사라진다**(에러도 로그도 없다).
     */
    public function test_context_budget_cuts_before_the_count_does(): void
    {
        $svc = app(AssistantService::class);
        $kb = [
            ['source' => '작은1', 'text' => str_repeat('가', 100), 'embedding' => [1.0, 0.0, 0.0]],
            ['source' => '거대',   'text' => str_repeat('나', 5000), 'embedding' => [0.0, 1.0, 0.0]],
            ['source' => '작은2', 'text' => str_repeat('다', 100), 'embedding' => [0.0, 0.0, 1.0]],
        ];
        $scored = [0 => 0.9, 1 => 0.8, 2 => 0.7];   // 거대 청크가 2위

        // 예산 1000자 — 거대 청크는 건너뛰고 **뒤의 작은 근거를 담는다**(break 가 아니라 continue).
        $picked = array_keys($svc->selectTopChunks($kb, $scored, 3, 1.0, 1000));
        $this->assertSame([0, 2], $picked, '예산을 넘는 청크가 뒤 근거의 자리를 잡아먹었다');

        // 예산을 끄면(0) 개수만 본다 — 순위 측정용 경로.
        $this->assertSame([0, 1, 2], array_keys($svc->selectTopChunks($kb, $scored, 3, 1.0, 0)));
    }

    /** 첫 청크는 예산을 넘겨도 담는다 — 근거 0장으로 답하게 만들 수는 없다. */
    public function test_the_first_chunk_is_kept_even_if_it_alone_blows_the_budget(): void
    {
        $svc = app(AssistantService::class);
        $kb = [['source' => '거대', 'text' => str_repeat('가', 9000), 'embedding' => [1.0, 0.0]]];

        $this->assertSame([0], array_keys($svc->selectTopChunks($kb, [0 => 0.9], 3, 1.0, 1000)));
    }

    /**
     * 🔒 시스템 프롬프트가 요구하는 것 5가지가 실제로 들어 있는지 **정적으로** 지킨다.
     *
     * 구 프롬프트는 «간결·정확하게» + «어디에도 관련 내용이 전혀 없을 때만» 두 마디뿐이라 실제 답변이
     * 한 문단으로 끝나고 필수 사실을 빠뜨렸다. 그 두 문구가 되살아나면 같은 증상이 돌아온다.
     * ⚠️ 기능 테스트로는 원리상 못 잡는다 — LLM 답변은 실행마다 달라 단언할 수 없고, 프롬프트가
     *    뭐든 화면은 정상 렌더된다.
     */
    public function test_guide_prompt_keeps_the_five_instructions(): void
    {
        $p = AssistantService::GUIDE_SYSTEM_PROMPT;

        // ① 구조
        $this->assertStringContainsString('직접 답', $p);
        $this->assertStringContainsString('조건·절차', $p);
        $this->assertStringContainsString('주의·한계', $p);
        // ② 근거 카드 밝히기 ③ 갈래 ④ 반대 정보 ⑤ 부분 부재
        $this->assertStringContainsString('카드 제목', $p);
        $this->assertStringContainsString('갈래', $p);
        $this->assertStringContainsString('자동이 아닌 것', $p);
        $this->assertStringContainsString('ERP에 확인된 정보가 없습니다', $p);

        // 2026-09-09 2차 — 나열을 막는 규칙(jin 제보: 한 질문에 25줄이 나왔다)
        $this->assertStringContainsString('필요한 것만', $p, '「필요한 것만 쓴다」가 없으면 참고자료를 통째로 옮긴다');
        $this->assertStringContainsString('서식 기호', $p, '위젯은 평문을 그대로 뿌린다 — 별표 금지 지시가 필요하다');
        $this->assertStringContainsString('근거 카드: ', $p, '근거 줄의 라벨을 고정하지 않으면 제목만 덩그러니 남는다');

        // 🚫 되살아나면 안 되는 구 문구
        $this->assertStringNotContainsString('간결', $p, '「간결하게」가 돌아오면 답이 다시 한 문단으로 줄어든다');
        $this->assertStringNotContainsString('어디에도 관련 내용이 전혀 없을 때만', $p, '부분 부재를 다룰 수 없는 구 문구가 돌아왔다');
        $this->assertStringNotContainsString('빠뜨리지 말고 적는다', $p, '이 문구가 「다 옮겨라」로 읽혀 25줄 나열을 만들었다');
    }

    /** 프롬프트도 문맥 예산을 먹는다 — 너무 길어지면 근거가 밀려난다. */
    public function test_guide_prompt_stays_within_its_share_of_the_budget(): void
    {
        $this->assertLessThan(
            (int) config('assistant.rag_ctx_chars', 4000) / 4,
            mb_strlen(AssistantService::GUIDE_SYSTEM_PROMPT),
            '시스템 프롬프트가 근거 예산의 1/4 을 넘었다 — 그만큼 근거가 줄어든다'
        );
    }
}
