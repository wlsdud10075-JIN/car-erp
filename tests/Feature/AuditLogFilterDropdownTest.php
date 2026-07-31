<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashSnapshot;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 감사로그 필터 드롭다운 (jin 2026-07-31 — "뭔가 어긋나 있고, 안 들어가야 할 게 들어가 있다").
 *
 * 드롭다운은 DB 의 distinct 값으로 만들어지므로, 컬럼이 아닌 값이 column_name 에 들어가는
 * 자리가 있으면 그대로 목록에 섞인다. 실제로 두 가지가 섞여 있었다:
 *   ① 챗봇 질문(action='assistant_query')의 질문 유형 — 컬럼이 아니다
 *   ② 'buyer:{바이어명}' — 값이 박힌 동적 키라 바이어 수만큼 늘어난다
 */
class AuditLogFilterDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        App::setLocale('ko');
        $u = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($u);

        return $u;
    }

    private function log(array $attrs): AuditLog
    {
        return AuditLog::create($attrs + [
            'user_id' => null, 'auditable_type' => Vehicle::class, 'auditable_id' => 1,
            'action' => 'updated', 'column_name' => null, 'old_value' => null, 'new_value' => null,
        ]);
    }

    public function test_chatbot_intents_are_not_listed_as_columns(): void
    {
        $this->actAsAdmin();
        $this->log(['auditable_type' => User::class, 'action' => 'assistant_query', 'column_name' => 'receivable_summary']);
        $this->log(['auditable_type' => User::class, 'action' => 'assistant_query', 'column_name' => 'capital_status(denied)']);
        $this->log(['column_name' => 'sale_price']);

        $columns = Volt::test('admin.audit-logs.index')->get('distinctColumns');

        $this->assertArrayHasKey('sale_price', $columns, '진짜 컬럼은 남아야 합니다.');
        $this->assertArrayNotHasKey('receivable_summary', $columns, '챗봇 질문 유형은 컬럼이 아닙니다.');
        $this->assertArrayNotHasKey('capital_status(denied)', $columns);
    }

    /** 챗봇 질문은 액션 필터에서 고른다 — 목록에서 사라지기만 하면 못 찾게 되므로. */
    public function test_chatbot_queries_remain_findable_via_the_action_filter(): void
    {
        $this->actAsAdmin();
        $this->log(['auditable_type' => User::class, 'action' => 'assistant_query', 'column_name' => 'guide']);

        $actions = Volt::test('admin.audit-logs.index')->get('distinctActions');

        $this->assertSame('챗봇 질문', $actions['assistant_query'] ?? null);
    }

    public function test_buyer_changes_collapse_into_one_option(): void
    {
        $this->actAsAdmin();
        foreach (['AUTO SCOUT', 'R.S.H', 'TRYM'] as $name) {
            $this->log(['column_name' => "buyer:{$name}"]);
        }

        $columns = Volt::test('admin.audit-logs.index')->get('distinctColumns');

        $this->assertArrayHasKey('buyer:*', $columns, '바이어 변경은 한 항목으로 접혀야 합니다.');
        $this->assertSame('바이어 변경', $columns['buyer:*']);
        $this->assertArrayNotHasKey('buyer:AUTO SCOUT', $columns, '바이어마다 항목이 생기면 안 됩니다.');
    }

    /** 접은 항목을 고르면 바이어 변경이 전부 잡혀야 한다(LIKE 처리). */
    public function test_collapsed_buyer_filter_matches_every_buyer_row(): void
    {
        $this->actAsAdmin();
        $this->log(['column_name' => 'buyer:AUTO SCOUT']);
        $this->log(['column_name' => 'buyer:TRYM']);
        $this->log(['column_name' => 'sale_price']);

        $rows = Volt::test('admin.audit-logs.index')->set('columnFilter', 'buyer:*')->get('logs');

        $this->assertSame(2, $rows->total());
    }

    /** 🚨 jin 이 못 찾은 것 — 「통장 잔액」은 대상(모델) 필터로 고른다. */
    public function test_cash_snapshot_is_selectable_as_a_target(): void
    {
        $this->actAsAdmin();
        $this->log(['auditable_type' => CashSnapshot::class, 'auditable_id' => 1, 'column_name' => 'balance_krw', 'new_value' => '1000000']);
        $this->log(['column_name' => 'sale_price']);

        $component = Volt::test('admin.audit-logs.index');
        $types = $component->get('distinctTypes');

        $this->assertSame('통장 잔액', $types[CashSnapshot::class] ?? null);

        $rows = $component->set('typeFilter', CashSnapshot::class)->get('logs');
        $this->assertSame(1, $rows->total(), '대상으로 걸러지면 통장 잔액 이력만 남아야 합니다.');
    }

    /** 잔액 컬럼도 한글로 뜬다(대상을 알기에 정확한 표의 라벨을 쓴다). */
    public function test_cash_columns_are_labelled_from_the_right_table(): void
    {
        $this->actAsAdmin();
        $this->log(['auditable_type' => CashSnapshot::class, 'column_name' => 'balance_krw']);

        $columns = Volt::test('admin.audit-logs.index')->get('distinctColumns');

        $this->assertSame('통장 잔액(원화)', $columns['balance_krw'] ?? null);
    }

    /** 라벨이 한글인데 영문 순으로 정렬하면 화면 순서가 뒤죽박죽으로 보인다. */
    public function test_dropdowns_are_sorted_by_the_korean_label(): void
    {
        $this->actAsAdmin();
        $this->log(['action' => 'updated']);
        $this->log(['action' => 'created']);
        $this->log(['action' => 'deleted']);

        $labels = array_values(Volt::test('admin.audit-logs.index')->get('distinctActions'));
        $sorted = $labels;
        sort($sorted, SORT_LOCALE_STRING);

        $this->assertSame($sorted, $labels, '드롭다운은 한글 라벨 기준으로 정렬되어야 합니다.');
    }
}
