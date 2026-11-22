<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\User;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🇰🇷 로그 화면 — 드롭박스는 치면서 찾는 콤보로, 선택지는 한글로 (jin 2026-11-22
 * «로그 기록 쪽 드롭박스에 콤보 적용이 안 되기도 했고, 영어로 된 것들도 많은데 한글로 볼 수 있게»).
 *
 * 운영 3사 실측(11-22): 감사로그 대상 3종·컬럼 21쌍·액션 1종, 문서접근로그 종류 16종 중 13종이 영문 그대로였다.
 */
class LogScreensKoreanCombosTest extends TestCase
{
    use RefreshDatabase;

    private function hasKorean(string $s): bool
    {
        return (bool) preg_match('/[가-힣]/u', $s);
    }

    public function test_audit_log_filters_are_comboboxes_not_selects(): void
    {
        $src = file_get_contents(resource_path('views/livewire/admin/audit-logs/index.blade.php'));
        foreach (['userFilter', 'actionFilter', 'typeFilter', 'columnFilter'] as $prop) {
            $this->assertDoesNotMatchRegularExpression('/<select\b[^>]*wire:model(?:\.[a-z.0-9]+)?="'.$prop.'"/', $src, "{$prop} 가 <select> 로 남아 있다");
            $this->assertMatchesRegularExpression('/<x-erp\.combobox\b(?:(?!\/>).)*model="'.$prop.'"(?:(?!\/>).)*\bdeferred\b/s', $src, "{$prop} 콤보가 없거나 deferred 가 아니다");
        }
    }

    /** 값이 글자인 목록(액션)도 고른 뒤 다시 그렸을 때 한글 이름이 칸에 남는다 — 콤보의 options 는 id 를 숫자로 읽어서 `extra` 로 넘겨야 한다. */
    public function test_a_chosen_action_keeps_its_korean_name_and_filters_on_search(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'payout_held', 'auditable_type' => 'App\\Models\\Vehicle', 'auditable_id' => 1, 'column_name' => 'payout_hold', 'new_value' => 'HELD-ROW']);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'updated', 'auditable_type' => 'App\\Models\\Vehicle', 'auditable_id' => 1, 'column_name' => 'sale_price', 'new_value' => 'OTHER-ROW']);

        $this->actingAs($admin);
        $html = Volt::test('admin.audit-logs.index')->set('actionFilter', 'payout_held')->call('searchNow')->html();

        $label = ColumnLabel::action('payout_held');
        $this->assertTrue($this->hasKorean($label));
        // 콤보의 초기값(selectedName)은 JS 문자열로 실린다 — 한글이 이스케이프(유니코드 코드)로 실릴 수 있어 두 모양을 다 본다.
        //   큰 HTML 이라 assertStringContainsString 대신 짧게 단언한다(실패 출력이 느리다).
        $decoded = html_entity_decode($html, ENT_QUOTES);
        $escaped = trim((string) json_encode($label), '"');
        $pos = strpos($decoded, 'selectedName:');
        $this->assertNotFalse($pos, '콤보가 안 그려졌다');
        $chunks = [];
        for ($off = 0; ($p = strpos($decoded, 'selectedName:', $off)) !== false; $off = $p + 1) {
            $chunks[] = substr($decoded, $p, 200);
        }
        $this->assertTrue(
            collect($chunks)->contains(fn ($c) => str_contains($c, $label) || str_contains($c, $escaped) || str_contains($c, str_replace('\\', '\\\\', $escaped))),
            '고른 액션의 한글 이름이 콤보 초기값에 없다'
        );
        $this->assertStringContainsString('HELD-ROW', $html);
        $this->assertStringNotContainsString('OTHER-ROW', $html);
    }

    public function test_labels_seen_in_production_are_korean(): void
    {
        foreach (['KakaoCollectorToken', 'KakaoRoom', 'VehicleClaim'] as $m) {
            $this->assertTrue($this->hasKorean(ColumnLabel::model('App\\Models\\'.$m)), "{$m} 대상 이름이 영문");
        }
        $this->assertTrue($this->hasKorean(ColumnLabel::action('kakao_room_fixed')));
        // 그 표의 사전에 없으면 다른 표의 같은 이름을 빌린다 — 영문 그대로 뜨지 않는다
        foreach ([['App\\Models\\Buyer', 'office_address'], ['App\\Models\\Buyer', 'city'], ['App\\Models\\Buyer', 'is_b2b_partner'],
            ['App\\Models\\Vehicle', 'tax_dc'], ['App\\Models\\Salesman', 'payout_hold'], ['App\\Models\\Vehicle', 'history_row'],
            ['App\\Models\\SettlementPayoutBatch', 'finalized_at']] as [$model, $col]) {
            $this->assertTrue($this->hasKorean(ColumnLabel::column($model, $col)), "{$model}.{$col} 이 영문으로 뜬다");
        }
        $this->assertSame('요청 대수 (3대)', ColumnLabel::columnAny('count:3'));
        $this->assertSame('no_such_column_xyz', ColumnLabel::column('App\\Models\\Vehicle', 'no_such_column_xyz'), '사전에 아예 없는 이름은 종전대로 원문');
    }

    public function test_document_access_log_types_are_korean(): void
    {
        // 운영 3사에 실제로 쌓인 종류(11-22 실측)
        $seen = ['bill_of_lading', 'clearance', 'clearance_v2', 'container_contract', 'container_invoice_packing', 'deregistration',
            'deregistration_certificate', 'deregistration_contract', 'deregistration_set', 'deregistration_upload', 'export_declaration',
            'invoice', 'poa', 'roro_contract', 'roro_invoice_packing', 'sales_contract', 'shipping_photos', 'transfer_certificate'];
        foreach ($seen as $type) {
            $label = (new DocumentAccessLog(['document_type' => $type]))->document_label;
            $this->assertTrue($this->hasKorean($label) || $label === 'B/L', "{$type} 이 「{$label}」 로 뜬다");
        }
        $this->assertSame('판매계약서 전자서명', (new DocumentAccessLog(['document_type' => 'sales_contract_signed']))->document_label);
    }
}
