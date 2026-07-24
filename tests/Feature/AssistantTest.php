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
        $this->assertSame('receivable_by_salesman', $svc->classify('담당자별 미수 현황'));
        $this->assertSame('receivable_by_buyer', $svc->classify('바이어별 미수금 보여줘'));
        $this->assertSame('receivable_summary', $svc->classify('채권 요약'));
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
        $this->assertStringContainsString('대표·최고관리자만', $res['answer']);
        // 감사에 denied 기록
        $this->assertTrue(AuditLog::where('action', 'assistant_query')
            ->where('column_name', 'capital_status(denied)')->exists());
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

    public function test_widget_requires_permission(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        Volt::actingAs($sales)->test('assistant.widget')
            ->set('q', '전체 미수 보여줘')
            ->call('send')
            ->assertStatus(403);
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
}
