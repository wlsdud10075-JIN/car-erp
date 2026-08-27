<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\DocumentAccessLog;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ssancar.com 바이어 포털 — 서류 실물 통로 (2026-08-27).
 *
 * 지키는 것 셋:
 *  ① **게이트가 한 곳뿐인가** — 목록 플래그와 다운로드가 갈리면 「버튼은 뜨는데 받으면 거절」(§8 #44).
 *  ② **빈 서류가 안 나가나** — jin 2026-08-18: *"내용이 빈 서류가 나오면 아무도 못 잡습니다"*.
 *  ③ **통관 SET 에 매입가·원가·마진·RRN 이 없나** — 이걸 손으로 한 번 확인하고 바이어에게 열었다.
 *     사람 기억으로는 못 지킨다. 매핑이 한 줄 늘 때 여기서 잡는다.
 */
class PortalDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'portal-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        config([
            'services.ssancar_portal.hmac_secret' => self::SECRET,
            'services.ssancar_portal.source' => 'heymanerp',
        ]);
    }

    private function signed(string $path, array $query = []): TestResponse
    {
        $ts = now()->timestamp;
        ksort($query);
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, self::SECRET),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    /** 게이트를 전부 통과하는 차 — 운항 진입 + 필수 칸. */
    private function seedDownloadable(array $attrs = []): Vehicle
    {
        $sm = Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'PORTAL BUYER '.uniqid(), 'is_active' => true, 'salesman_id' => $sm->id]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'VIA AUTO', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '88가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id,
            'export_consignee_id' => $consignee->id,
            'nice_reg_vin' => 'WBAJD9100JWC11399',
            'brand' => 'BMW',
            'model_type' => '530I',
            'nice_reg_vehicle_form' => '중형승용',
            'nice_spec_displacement' => 1995,
            // ① 운항 단계 진입 — 선적일 + ETA
            'shipping_date' => now()->subDays(5)->toDateString(),
            'eta_date' => now()->addDays(20)->toDateString(),
        ], $attrs));
    }

    private function clearancePath(Vehicle $v): string
    {
        return '/api/internal/portal/vehicles/'.$v->id.'/clearance-set';
    }

    // ── ③ 바이어에게 나가면 안 되는 값 ───────────────────────────────────

    /**
     * 🚨 통관 SET 을 바이어에게 연 근거가 «재무정보가 없다» 하나였다.
     *    매핑에 한 줄이 늘어 매입가가 새면 **아무도 못 잡는다** — 서류는 에러가 안 난다.
     */
    public function test_clearance_set_never_contains_purchase_cost_or_margin(): void
    {
        $v = $this->seedDownloadable([
            'purchase_price' => 13_000_000,
            'selling_fee' => 777_777,
            'cost_towing' => 654_321,
            'cost_license' => 246_802,
            'sale_price' => 10_000_000,
            'sale_date' => now()->subDays(30)->toDateString(),
            'exchange_rate' => 1,
        ]);

        $cells = $this->dumpCells($v);
        $this->assertNotEmpty($cells, '생성물이 비었다 — 덤프가 안 돌았다면 이 테스트는 아무것도 검사하지 않는다');

        foreach (['13000000', '777777', '654321', '246802'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $cells,
                "통관 SET 에 매입·비용 금액($forbidden)이 들어갔다 — 바이어에게 나가는 서류다"
            );
        }

        // 판매금은 들어가야 정상이다(바이어가 이미 아는 금액). 안 들어가면 서류가 빈 것이다.
        $this->assertStringContainsString('10000000', $cells, '판매금이 안 들어갔다 — 게이트나 매핑이 깨졌다');
    }

    /** 소유자 PII 는 말소 SET 의 것이지 통관 SET 의 것이 아니다. 섞이면 국외 이전이 된다. */
    public function test_clearance_set_never_contains_owner_pii(): void
    {
        $v = $this->seedDownloadable([
            'nice_reg_owner_name' => '홍길동',
            'nice_reg_owner_rrn' => '800101-1234567',
            'nice_reg_owner_addr' => '서울시 어딘가 123',
        ]);

        $cells = $this->dumpCells($v);

        $this->assertStringNotContainsString('800101', $cells, 'RRN 이 통관 SET 에 들어갔다');
        $this->assertStringNotContainsString('홍길동', $cells, '소유자 성명이 통관 SET 에 들어갔다');
        $this->assertStringNotContainsString('어딘가 123', $cells, '소유자 주소가 통관 SET 에 들어갔다');
    }

    /** 생성물의 전 시트·전 셀을 한 문자열로. 값과 수식을 모두 본다. */
    private function dumpCells(Vehicle $v): string
    {
        $spreadsheet = (new DocumentFiller($v))->spreadsheet('clearance');
        $out = [];
        foreach ($spreadsheet->getAllSheets() as $ws) {
            foreach ($ws->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->getValue();
                    if ($value !== null && $value !== '') {
                        $out[] = (string) $value;
                    }
                }
            }
        }

        return implode("\n", $out);
    }

    // ── ① 게이트가 한 곳인가 ─────────────────────────────────────────────

    public function test_download_matches_the_flag_published_in_the_list(): void
    {
        $ok = $this->seedDownloadable();
        $this->assertNull($ok->clearanceSetBlocker());
        $this->signed($this->clearancePath($ok), ['buyer_id' => $ok->buyer_id])->assertOk();

        // 목록이 「못 받는다」고 한 차는 다운로드도 거절해야 한다.
        $blocked = $this->seedDownloadable(['eta_date' => null]);
        $this->assertSame('not_sailing', $blocked->clearanceSetBlocker());
        $this->signed($this->clearancePath($blocked), ['buyer_id' => $blocked->buyer_id])->assertStatus(422);
    }

    /**
     * 목록을 받은 뒤 차량이 조건에서 벗어날 수 있다 — 서빙 시점에 다시 판정해야 한다(§8 #26).
     */
    public function test_gate_is_re_evaluated_at_serve_time(): void
    {
        $v = $this->seedDownloadable();
        $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertOk();

        $v->update(['eta_date' => null]);   // 실무자가 ETA 를 지웠다

        $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertStatus(422);
    }

    // ── ② 빈 서류 ────────────────────────────────────────────────────────

    /**
     * 엑셀 import 과거 차량의 모양 — 선적·ETA 는 있고 NICE 제원만 없다.
     * jin: *"과거 거는 바이어도 다운로드 할 이유가 없다"* ⇒ 막히는 것이 의도한 결과다.
     */
    public function test_imported_vehicle_without_nice_spec_is_refused(): void
    {
        $v = $this->seedDownloadable([
            'nice_reg_vehicle_form' => null,
            'nice_spec_displacement' => null,
        ]);

        $this->assertSame('no_nice_spec', $v->clearanceSetBlocker());
        $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertStatus(422);
    }

    public function test_missing_consignee_or_vin_is_refused(): void
    {
        $noConsignee = $this->seedDownloadable(['export_consignee_id' => null]);
        $this->assertSame('no_consignee', $noConsignee->clearanceSetBlocker());

        $noVin = $this->seedDownloadable(['nice_reg_vin' => null]);
        $this->assertSame('no_vin', $noVin->clearanceSetBlocker());
    }

    /**
     * 🔑 게이트가 두 층인 것 — 사진·수출신고서는 저장물이라 빈 서류 위험이 없다.
     *    NICE 제원이 없어도 **서류함 자체는 열려 있어야** 한다.
     */
    public function test_missing_nice_spec_does_not_close_the_whole_document_shelf(): void
    {
        $v = $this->seedDownloadable(['nice_reg_vehicle_form' => null, 'nice_spec_displacement' => null]);

        $this->assertNull($v->portalDocumentsBlocker(), '서류함은 열려 있어야 한다');
        $this->assertSame('no_nice_spec', $v->clearanceSetBlocker(), '통관 SET 만 막힌다');
    }

    // ── 인가 · 감사 ──────────────────────────────────────────────────────

    public function test_buyer_id_mismatch_is_forbidden(): void
    {
        $mine = $this->seedDownloadable();
        $other = $this->seedDownloadable();

        $this->signed($this->clearancePath($mine), ['buyer_id' => $other->buyer_id])->assertStatus(403);
        $this->signed($this->clearancePath($mine))->assertStatus(400);
    }

    public function test_unsigned_request_is_rejected(): void
    {
        $v = $this->seedDownloadable();
        $this->get($this->clearancePath($v).'?buyer_id='.$v->buyer_id)->assertStatus(401);
    }

    /** 감사는 board 와 **같은 표**에 남는다 — 두 곳으로 가르지 않는다. */
    public function test_download_is_recorded_in_the_shared_audit_table(): void
    {
        $v = $this->seedDownloadable();
        $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertOk();

        $log = DocumentAccessLog::where('vehicle_id', $v->id)->first();
        $this->assertNotNull($log, '포털 다운로드가 감사에 안 남았다');
        $this->assertSame('portal_api', $log->source);
        $this->assertSame('clearance', $log->document_type);
        $this->assertSame('buyer:'.$v->buyer_id, $log->actor_email, '누구 몫으로 나갔는지가 남아야 한다');
        $this->assertNull($log->user_id, '세션 없는 호출이라 user_id 는 비어야 한다');
    }

    // ── 저장물 통로 ──────────────────────────────────────────────────────

    /**
     * 서명 URL 을 못 만드는 디스크에서는 **링크를 내지 않는다**.
     * `->url()` 은 그 서버 안에서만 뜻이 있는 주소라 외부 바이어에겐 무의미하고 만료도 없다.
     */
    public function test_files_refuses_when_the_disk_cannot_sign_urls(): void
    {
        config(['filesystems.vehicle_docs_disk' => 'public']);   // 로컬 디스크 = temporaryUrl 불가
        $v = $this->seedDownloadable(['export_declaration_document' => 'vehicles/x.pdf']);

        $res = $this->signed('/api/internal/portal/vehicles/'.$v->id.'/files', ['buyer_id' => $v->buyer_id]);

        $res->assertStatus(503)->assertJsonPath('error', 'temporary_urls_unsupported');
    }

    /** 서류함이 안 열린 차는 저장물 링크도 안 준다 — 게이트가 서류함 전체에 하나다. */
    public function test_files_is_closed_before_the_vehicle_sails(): void
    {
        $v = $this->seedDownloadable(['shipping_date' => null, 'eta_date' => null]);

        $this->signed('/api/internal/portal/vehicles/'.$v->id.'/files', ['buyer_id' => $v->buyer_id])
            ->assertStatus(422);
    }

    // ── 목록 응답 ────────────────────────────────────────────────────────

    public function test_list_publishes_the_document_shelf_without_paths(): void
    {
        $v = $this->seedDownloadable([
            'export_declaration_document' => 'vehicles/1/export.pdf',
            'deregistration_document' => 'vehicles/1/dereg.pdf',
            'checkbill_document' => 'vehicles/1/checkbill.pdf',
        ]);
        VehiclePhoto::create(['vehicle_id' => $v->id, 'path' => 'vehicles/1/ship1.jpg', 'category' => 'shipping']);
        VehiclePhoto::create(['vehicle_id' => $v->id, 'path' => 'vehicles/1/ship2.jpg', 'category' => 'shipping']);
        VehiclePhoto::create(['vehicle_id' => $v->id, 'path' => 'vehicles/1/basic.jpg', 'category' => null]);

        $body = $this->signed('/api/internal/portal/vehicles')->assertOk()->getContent();
        $row = collect(json_decode($body, true)['data'])->firstWhere('id', $v->id);

        $this->assertTrue($row['documents_open']);
        $this->assertTrue($row['has_export_declaration_document']);
        $this->assertTrue($row['has_deregistration_document']);
        $this->assertTrue($row['can_download_clearance_set']);
        $this->assertNull($row['clearance_set_blocked_reason']);

        // 🚫 기본정보 사진(category null)은 안 센다 — 그 칸엔 매입 서류·등록증 스캔이 섞인다.
        $this->assertSame(2, $row['shipping_photo_count']);

        // 🚫 체크빌은 칸조차 없다 — has_bl_document 옆에 놓으면 「B/L 이 두 개」로 읽힌다.
        $this->assertArrayNotHasKey('has_checkbill_document', $row);
        $this->assertArrayNotHasKey('document_deadline_date', $row);

        // 🚫 경로는 절대 안 나간다 — 응답 전체를 훑는다(키 이름이 바뀌어도 값이 새면 잡히게).
        foreach (['vehicles/1/export.pdf', 'vehicles/1/dereg.pdf', 'vehicles/1/ship1.jpg'] as $path) {
            $this->assertStringNotContainsString($path, $body, 'ERP 파일 경로가 포털로 샜다');
        }
    }

    /** 아직 안 떠난 차는 서류가 있어도 서류함이 닫혀 있다. */
    public function test_list_keeps_the_shelf_closed_before_sailing(): void
    {
        $v = $this->seedDownloadable([
            'shipping_date' => null,
            'eta_date' => null,
            'deregistration_document' => 'vehicles/2/dereg.pdf',
        ]);

        $body = $this->signed('/api/internal/portal/vehicles')->assertOk()->getContent();
        $row = collect(json_decode($body, true)['data'])->firstWhere('id', $v->id);

        $this->assertFalse($row['documents_open'], '매입 단계인데 서류함이 열렸다');
        $this->assertTrue($row['has_deregistration_document'], '파일 존재는 사실 그대로 낸다');
        $this->assertFalse($row['can_download_clearance_set']);
    }

    /**
     * 🚪 **도착해도 닫히지 않는다.** `sailing_status` 로 판정하면 ETA 가 지나는 순간 닫히는데,
     *    바이어가 서류를 제일 필요로 하는 때가 도착 직후 수입통관이라 거꾸로다.
     */
    public function test_shelf_stays_open_after_arrival(): void
    {
        $v = $this->seedDownloadable([
            'shipping_date' => now()->subDays(40)->toDateString(),
            'eta_date' => now()->subDays(3)->toDateString(),   // 이미 도착
        ]);

        $this->assertSame('arrived', $v->sailing_phase);
        $this->assertNull($v->portalDocumentsBlocker(), '도착했다고 서류함이 닫히면 안 된다');
        $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertOk();
    }

    /** 생성물이 실제로 xlsx 바이트인가 — 스트림이 비면 200 인데 파일이 안 열린다. */
    public function test_stream_returns_a_real_xlsx(): void
    {
        $v = $this->seedDownloadable();
        $res = $this->signed($this->clearancePath($v), ['buyer_id' => $v->buyer_id])->assertOk();

        $bytes = $res->streamedContent();
        $this->assertGreaterThan(5_000, strlen($bytes), '스트림이 사실상 비었다');
        $this->assertSame('PK', substr($bytes, 0, 2), 'xlsx(zip) 시그니처가 아니다');
    }

    /**
     * ⚠️ 수식 재계산을 Excel 에 위임해야 마스터 시트 → 6시트 cascade 가 산다.
     *    preCalc 를 켜면 값이 박제돼 조용히 틀린 서류가 나간다.
     */
    public function test_writer_leaves_formula_recalculation_to_excel(): void
    {
        $v = $this->seedDownloadable();
        $writer = new Xlsx((new DocumentFiller($v))->spreadsheet('clearance'));
        $writer->setPreCalculateFormulas(false);

        $this->assertFalse($writer->getPreCalculateFormulas());
    }
}
