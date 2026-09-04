<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleDocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 서류 일괄 업로드 — 공유형(체크빌·수출신고서). 기획 = docs/design/bulk-document-upload.md.
 *
 * 핵심 불변식:
 *   ① 파일 1개 → 차량마다 **다른 경로**. 한 경로를 공유하면 한 대를 교체할 때 나머지가 깨진다.
 *   ② 이미 파일 있는 차는 **기본으로 건너뛴다**. 교체는 사람이 명시로 켤 때만.
 *   ③ 번호가 섞이면 확인 없이는 진행 못 한다 — 면장은 신고번호마다 다르다.
 *   ④ 같은 번호인데 선택 밖인 차를 알려준다 — 묶음이 반쪽으로 남지 않게.
 *
 * ⚠️ `store()` 반환값 검사(SKILLS §8 #47)는 여기서 못 잡는다 — `Storage::fake()` 는 로컬 드라이버라
 *    쓰기가 늘 성공한다. 그 가드는 정적 검사(FileWriteResultCheckedTest) 몫이다.
 */
class BulkVehicleDocumentTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('filesystems.vehicle_docs_disk');
        Storage::fake($this->disk);
    }

    private function vehicle(string $number, array $attrs = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => $number, 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
        ], $attrs));
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function file(string $name = 'decl.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /** 내용까지 구분해야 「행마다 다른 파일」을 진짜로 검증할 수 있다. */
    private function fileWith(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function svc(): BulkVehicleDocumentService
    {
        return app(BulkVehicleDocumentService::class);
    }

    // ── ① 파일 1개 → 차량마다 다른 경로 ────────────────────────────────

    public function test_one_file_lands_on_every_target_at_its_own_path(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $b = $this->vehicle('22나2002', ['export_declaration_number' => 'D-1']);

        $r = $this->svc()->applyShared([$a->id, $b->id], 'export_declaration', $this->file(), $user, false, '테스트');

        $this->assertSame(2, $r['applied']);
        $pathA = $a->fresh()->export_declaration_document;
        $pathB = $b->fresh()->export_declaration_document;
        $this->assertNotEmpty($pathA);
        $this->assertNotEmpty($pathB);
        $this->assertNotSame($pathA, $pathB, '한 경로를 공유하면 한 대를 교체할 때 나머지가 깨진다');
        Storage::disk($this->disk)->assertExists($pathA);
        Storage::disk($this->disk)->assertExists($pathB);
        $this->assertStringContainsString("vehicles/{$a->id}/", $pathA);
    }

    /** 같은 파일을 여러 번 저장해도 두 번째부터 조용히 실패하지 않는다(임시파일 소진 방지). */
    public function test_the_same_upload_survives_being_written_many_times(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $ids = collect(range(1, 5))->map(fn ($i) => $this->vehicle("1{$i}가100{$i}")->id)->all();

        $r = $this->svc()->applyShared($ids, 'checkbill', $this->file('cb.pdf'), $user, false, '테스트');

        $this->assertSame(5, $r['applied']);
        $this->assertSame([], $r['skipped']);
        foreach (Vehicle::whereIn('id', $ids)->get() as $v) {
            Storage::disk($this->disk)->assertExists($v->checkbill_document);
        }
    }

    // ── ② 이미 있는 파일 ────────────────────────────────────────────

    public function test_existing_file_is_skipped_unless_replace_is_on(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001');
        $v->update(['checkbill_document' => 'vehicles/'.$v->id.'/old.pdf']);
        Storage::disk($this->disk)->put('vehicles/'.$v->id.'/old.pdf', 'old');

        $r = $this->svc()->applyShared([$v->id], 'checkbill', $this->file(), $user, false, '테스트');

        $this->assertSame(0, $r['applied']);
        $this->assertSame('has_file', $r['skipped'][0]['reason']);
        $this->assertSame('vehicles/'.$v->id.'/old.pdf', $v->fresh()->checkbill_document);
    }

    public function test_replace_overwrites_and_removes_the_old_file(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001');
        $old = 'vehicles/'.$v->id.'/old.pdf';
        $v->update(['checkbill_document' => $old]);
        Storage::disk($this->disk)->put($old, 'old');

        $r = $this->svc()->applyShared([$v->id], 'checkbill', $this->file(), $user, true, '테스트');

        $this->assertSame(1, $r['applied']);
        $new = $v->fresh()->checkbill_document;
        $this->assertNotSame($old, $new);
        Storage::disk($this->disk)->assertExists($new);
        Storage::disk($this->disk)->assertMissing($old);
    }

    // ── ③ 섞임 / ④ 빠짐 ────────────────────────────────────────────

    public function test_preview_reports_mixed_numbers_and_vehicles_left_out(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $b = $this->vehicle('22나2002', ['export_declaration_number' => 'D-2']);
        $left = $this->vehicle('33다3003', ['export_declaration_number' => 'D-1']);   // 같은 번호인데 선택 밖

        $p = $this->svc()->preview([$a->id, $b->id], 'export_declaration', $user);

        $this->assertSame(['D-1' => 1, 'D-2' => 1], $p['breakdown']);
        $this->assertSame(2, BulkVehicleDocumentService::distinctGroupCount($p['breakdown']));
        $this->assertSame([$left->id], array_column($p['outside'], 'id'));
    }

    /** 체크빌은 묶음 번호가 없다 — 섞임·빠짐 검사를 아예 하지 않는다(jin 2026-09-04). */
    public function test_checkbill_has_no_grouping_check(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $this->vehicle('33다3003', ['export_declaration_number' => 'D-1']);

        $p = $this->svc()->preview([$a->id], 'checkbill', $user);

        $this->assertSame([], $p['breakdown']);
        $this->assertSame([], $p['outside'], '체크빌에 묶음 잔여가 뜨면 안 된다');
    }

    public function test_screen_blocks_mixed_numbers_until_acknowledged(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $b = $this->vehicle('22나2002', ['export_declaration_number' => 'D-2']);

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id, (string) $b->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocFile', $this->file())
            ->call('applyBulkDoc');

        $c->assertSet('bulkDocOpen', true);                 // 모달이 안 닫힌다 = 적용 안 됨
        $this->assertNull($a->fresh()->export_declaration_document);
        $this->assertNull($b->fresh()->export_declaration_document);

        $c->set('bulkDocMixedAck', true)->call('applyBulkDoc')->assertSet('bulkDocOpen', false);
        $this->assertNotNull($a->fresh()->export_declaration_document);
        $this->assertNotNull($b->fresh()->export_declaration_document);
    }

    public function test_screen_includes_the_rest_of_the_group_by_default(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $left = $this->vehicle('33다3003', ['export_declaration_number' => 'D-1']);

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocFile', $this->file())
            ->call('applyBulkDoc')
            ->assertSet('bulkDocOpen', false);

        $this->assertNotNull($left->fresh()->export_declaration_document, '묶음이 반쪽으로 남았다');
    }

    public function test_screen_can_leave_the_rest_of_the_group_alone(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', ['export_declaration_number' => 'D-1']);
        $left = $this->vehicle('33다3003', ['export_declaration_number' => 'D-1']);

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocIncludeOutside', false)
            ->set('bulkDocFile', $this->file())
            ->call('applyBulkDoc');

        $this->assertNotNull($a->fresh()->export_declaration_document);
        $this->assertNull($left->fresh()->export_declaration_document);
    }

    // ── 권한·스코프 ────────────────────────────────────────────────

    /**
     * 권한은 단건 화면과 같다 — 영업은 말소증만, 통관·재무 서류는 못 만진다.
     * 재무는 아무것도 못 올린다(단건 화면에서도 서류 칸이 없다).
     */
    public function test_document_types_follow_the_single_vehicle_permissions(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);

        $this->assertSame(['deregistration'], BulkVehicleDocumentService::allowedFor($sales));
        $this->assertSame(
            ['checkbill', 'export_declaration', 'bl', 'deregistration'],
            BulkVehicleDocumentService::allowedFor($clearance)
        );
        $this->assertSame([], BulkVehicleDocumentService::allowedFor($finance), '재무는 버튼 자체가 안 떠야 한다');
    }

    public function test_sales_role_cannot_upload_clearance_documents(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);
        $v = $this->vehicle('11가1001');

        $this->expectException(AuthorizationException::class);
        $this->svc()->applyShared([$v->id], 'checkbill', $this->file(), $sales, false, '테스트');
    }

    /**
     * [관리] 는 서류 업로드 권한은 있지만 스코프가 **본인 팀**이다 — 남의 팀 차는 건드리면 안 된다.
     * (수출통관·재무·admin 은 전 차량이라 이 갈래가 안 생긴다.)
     */
    public function test_vehicle_outside_scope_is_skipped_not_written(): void
    {
        $mgr = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $subUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업',
            'manager_user_id' => $mgr->id, 'email_verified_at' => now(),
        ]);
        $mineSm = Salesman::firstOrCreate(['name' => 'MINE'], ['type' => 'employee', 'is_active' => true]);
        $mineSm->update(['user_id' => $subUser->id]);
        $otherSm = Salesman::firstOrCreate(['name' => 'OTHER'], ['type' => 'employee', 'is_active' => true]);
        $this->actingAs($mgr);

        $mine = $this->vehicle('11가1001', ['salesman_id' => $mineSm->id]);
        $theirs = $this->vehicle('22나2002', ['salesman_id' => $otherSm->id]);

        $this->assertTrue($mgr->canAccessClearance(), '권한은 있어야 스코프 갈래가 검사된다');

        $r = $this->svc()->applyShared([$mine->id, $theirs->id], 'checkbill', $this->file(), $mgr, false, '테스트');

        $this->assertSame(1, $r['applied']);
        $this->assertSame('no_scope', $r['skipped'][0]['reason']);
        $this->assertNotNull($mine->fresh()->checkbill_document);
        $this->assertNull($theirs->fresh()->checkbill_document, '남의 팀 차에 서류가 붙었다');

        // 미리보기도 같은 판정이어야 한다 — 화면에 뜨는데 저장은 안 되면 사람이 못 알아챈다.
        $p = $this->svc()->preview([$mine->id, $theirs->id], 'checkbill', $mgr);
        $this->assertSame([$mine->id], array_column($p['targets'], 'id'));
        $this->assertSame(['22나2002'], $p['no_scope']);
    }

    // ── 감사·파급 ──────────────────────────────────────────────────

    public function test_audit_records_both_the_batch_and_the_column_change(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001');

        $this->svc()->applyShared([$v->id], 'checkbill', $this->file(), $user, false, '9월 1차 선적분');

        $this->assertTrue(AuditLog::where('action', 'bulk_document_uploaded')
            ->where('auditable_id', $v->id)->where('new_value', '9월 1차 선적분')->exists());

        // 컬럼 변경 자체도 남아야 한다 — AUDITED_COLUMNS 에 서류 3종을 넣은 이유.
        $this->assertContains('checkbill_document', Vehicle::AUDITED_COLUMNS);
        $this->assertContains('export_declaration_document', Vehicle::AUDITED_COLUMNS);
        $this->assertContains('bl_document', Vehicle::AUDITED_COLUMNS);
    }

    /** 수출신고서는 v4 cascade #4 — 반입지가 있으면 올리는 순간 진행상태가 바뀐다. */
    public function test_declaration_upload_moves_progress_status(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', [
            'bl_loading_location' => '평택항',
            'export_declaration_number' => 'D-1',
        ]);
        $this->assertSame('선적중', $v->fresh()->progress_status);

        $this->svc()->applyShared([$v->id], 'export_declaration', $this->file(), $user, false, '테스트');

        $this->assertSame('선적완료', $v->fresh()->progress_status);
        $this->assertSame('선적완료', $v->fresh()->progress_status_cache);
    }

    // ── 개별형(말소증) — 차량마다 다른 파일 ─────────────────────────

    public function test_individual_mode_gives_each_vehicle_its_own_file(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001');
        $b = $this->vehicle('22나2002');

        $r = $this->svc()->applyIndividual(
            [$a->id => $this->fileWith('a.pdf', 'AAAA'), $b->id => $this->fileWith('b.pdf', 'BBBB')],
            'deregistration', $user, false, '테스트',
        );

        $this->assertSame(2, $r['applied']);
        $pa = $a->fresh()->deregistration_document;
        $pb = $b->fresh()->deregistration_document;
        $this->assertNotSame($pa, $pb);
        $this->assertStringContainsString("vehicles/{$a->id}/", $pa);
        $this->assertStringContainsString("vehicles/{$b->id}/", $pb);
        // 🔑 핵심 — 행마다 **그 행의 파일**이 가야 한다. 경로만 달라도 내용이 섞이면 말소증이 뒤바뀐다.
        $this->assertSame('AAAA', Storage::disk($this->disk)->get($pa));
        $this->assertSame('BBBB', Storage::disk($this->disk)->get($pb));
    }

    /** 빈 칸은 「안 건드림」이지 지우기가 아니다 — 파일을 안 넣은 행은 그대로 남는다. */
    public function test_rows_without_a_file_are_left_alone(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001');
        $b = $this->vehicle('22나2002');

        $r = $this->svc()->applyIndividual([$a->id => $this->file(), $b->id => null], 'deregistration', $user, false, '테스트');

        $this->assertSame(1, $r['applied']);
        $this->assertNotNull($a->fresh()->deregistration_document);
        $this->assertNull($b->fresh()->deregistration_document);
    }

    public function test_individual_mode_refuses_more_than_the_limit(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $files = [];
        for ($i = 1; $i <= BulkVehicleDocumentService::INDIVIDUAL_MAX + 1; $i++) {
            $files[$this->vehicle(sprintf('%02d가%04d', $i % 100, $i))->id] = $this->file();
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->applyIndividual($files, 'deregistration', $user, false, '테스트');
    }

    /** 공유형 메서드에 개별형을 넘기면 거부한다(모드를 섞으면 같은 파일이 N대에 붙는다). */
    public function test_modes_cannot_be_crossed(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001');

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->applyShared([$v->id], 'deregistration', $this->file(), $user, false, '테스트');
    }

    public function test_screen_uploads_a_different_file_per_row(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001');
        $b = $this->vehicle('22나2002');

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $a->id, (string) $b->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'deregistration')
            ->set('bulkDocFiles.'.$a->id, $this->fileWith('a.pdf', 'AAAA'))
            ->set('bulkDocFiles.'.$b->id, $this->fileWith('b.pdf', 'BBBB'))
            ->call('applyBulkDoc')
            ->assertSet('bulkDocOpen', false);

        $this->assertSame('AAAA', Storage::disk($this->disk)->get($a->fresh()->deregistration_document));
        $this->assertSame('BBBB', Storage::disk($this->disk)->get($b->fresh()->deregistration_document));
    }

    // ── 4단계 B/L — 유일하게 게이트가 있는 서류 ─────────────────────

    private function blReady(string $number, array $attrs = []): Vehicle
    {
        return $this->vehicle($number, array_merge([
            'bl_number' => 'KMTC-1',
            'bl_loading_location' => '평택항',
            'sale_price' => 10_000_000,
            'sale_date' => now()->toDateString(),
        ], $attrs));
    }

    /** 완납 + 반입지 = 통과. 붙는 순간 거래완료가 되고 출고일이 자동으로 채워진다. */
    public function test_bl_upload_completes_the_deal_and_fills_release_date(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->blReady('11가1001', ['shipping_date' => '2026-08-01']);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10_000_000,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);
        $v->refresh();
        $this->assertNull($v->blUploadBlocker(), '완납·반입지 있으면 통과해야 한다');

        $r = $this->svc()->applyShared([$v->id], 'bl', $this->file('bl.pdf'), $user, false, '테스트');

        $this->assertSame(1, $r['applied']);
        $this->assertSame('거래완료', $v->fresh()->progress_status);
        $this->assertNotNull($v->fresh()->warehouse_out_date, '거래완료 진입 시 출고일이 자동으로 채워진다');
    }

    /** 미완납은 건너뛴다 — 예외로 죽지 않고 사유와 함께 리포트된다. */
    public function test_unpaid_vehicle_is_skipped_with_a_reason(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->blReady('11가1001');

        $this->assertSame(Vehicle::BL_BLOCK_UNPAID, $v->blUploadBlocker());

        $r = $this->svc()->applyShared([$v->id], 'bl', $this->file(), $user, false, '테스트');

        $this->assertSame(0, $r['applied']);
        $this->assertSame(Vehicle::BL_BLOCK_UNPAID, $r['skipped'][0]['reason']);
        $this->assertNull($v->fresh()->bl_document);
    }

    public function test_missing_loading_location_is_blocked_before_the_ratio(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->blReady('11가1001', ['bl_loading_location' => null]);

        $this->assertSame(Vehicle::BL_BLOCK_NO_LOADING_LOCATION, $v->blUploadBlocker());
        $this->assertSame(0, $this->svc()->applyShared([$v->id], 'bl', $this->file(), $user, false, 'x')['applied']);
    }

    /** 🔑 미리보기와 실행이 같은 판정을 써야 한다 — 갈리면 「목록엔 되는데 저장이 안 되는」 행이 남는다. */
    public function test_preview_marks_exactly_what_apply_will_skip(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $ok = $this->blReady('11가1001');
        FinalPayment::create([
            'vehicle_id' => $ok->id, 'type' => 'balance', 'amount' => 10_000_000,
            'payment_date' => now()->toDateString(), 'confirmed_at' => now(),
        ]);
        $blocked = $this->blReady('22나2002');

        $p = $this->svc()->preview([$ok->id, $blocked->id], 'bl', $user);
        $previewBlocked = collect($p['targets'])->whereNotNull('blocked')->pluck('id')->all();

        $r = $this->svc()->applyShared([$ok->id, $blocked->id], 'bl', $this->file(), $user, false, '테스트');
        $applyBlocked = collect($r['skipped'])->pluck('id')->all();

        $this->assertSame($previewBlocked, $applyBlocked, '미리보기와 실행의 차단 대상이 갈렸다');
        $this->assertSame([$blocked->id], $applyBlocked);
    }

    /** 게이트 상수는 화면 문구가 있어야 한다 — 없으면 사유칸에 키 문자열이 그대로 찍힌다. */
    public function test_every_block_reason_has_a_label_in_both_locales(): void
    {
        foreach ([Vehicle::BL_BLOCK_NO_LOADING_LOCATION, Vehicle::BL_BLOCK_NO_SALE_PRICE, Vehicle::BL_BLOCK_UNPAID] as $reason) {
            foreach (['ko', 'en'] as $locale) {
                $key = 'vehicle.bulk_doc.blocked_'.$reason;
                $this->assertNotSame($key, (string) __($key, [], $locale), "[$locale] {$key} 번역 누락");
            }
        }
    }
}
