<?php

namespace Tests\Feature;

use App\Services\SystemHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 아침 점검 「board→ERP 전송·정합성」 2행 (jin 2026-09-28, 야간 배치 실행기 1단계 §12-1 D).
 *
 * 지키는 것:
 *   ① 경로(BOARD_AUDIT_JSON)가 비면 행이 **아예 없다** — board 없는 회사에 「기록 없음」이 매일 뜨면 소음.
 *   ② 파일이 없으면 「기록 없음」= 정상 / 오래되면 X — 감사 명령이 죽어 조용해진 것을 정상으로 읽지 않는다.
 *   ③ 숫자는 board JSON 을 옮겨 적을 뿐 여기서 판정하지 않는다.
 *   ④ board 가 ERP 대조에 실패했으면(missing_in_erp=null) 0 이 아니라 X.
 */
class BoardSyncHealthRowsTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('app/testing-board-audit-'.uniqid().'.json');
        config(['services.board_read.audit_json' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::delete($this->path);
        parent::tearDown();
    }

    private function rows(): array
    {
        $out = [];
        foreach (app(SystemHealthReport::class)->rows() as $r) {
            $out[$r['key']] = $r;
        }

        return $out;
    }

    private function writeAudit(array $counts, ?array $missing = [], array $errors = [], ?int $ageDays = null): void
    {
        File::put($this->path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'counts' => $counts,
            'stalled' => [],
            'missing_in_erp' => $missing,
            'errors' => $errors,
        ]));
        if ($ageDays !== null) {
            touch($this->path, now()->subDays($ageDays)->timestamp);
        }
    }

    public function test_rows_are_absent_when_no_path_is_configured(): void
    {
        config(['services.board_read.audit_json' => '']);
        $rows = $this->rows();
        $this->assertArrayNotHasKey('board_sync_stalled', $rows);
        $this->assertArrayNotHasKey('board_sync_integrity', $rows);
    }

    public function test_missing_file_is_no_record_not_a_failure(): void
    {
        $rows = $this->rows();
        $this->assertTrue($rows['board_sync_stalled']['ok']);
        $this->assertSame(__('health.no_record'), $rows['board_sync_stalled']['detail']);
        $this->assertTrue($rows['board_sync_integrity']['ok']);
    }

    public function test_all_zero_is_healthy(): void
    {
        $this->writeAudit(['stalled' => 0, 'synced_without_erp_id' => 0, 'erp_id_without_synced' => 0, 'missing_in_erp' => 0]);
        $rows = $this->rows();
        $this->assertTrue($rows['board_sync_stalled']['ok']);
        $this->assertTrue($rows['board_sync_integrity']['ok']);
    }

    /** 🔀 2026-10-06 — ERP 에서 지운 차(deleted_in_erp)는 실패가 아니다. 참고 건수만 붙고 행은 정상. */
    public function test_vehicles_deleted_in_erp_are_an_info_count_not_a_failure(): void
    {
        $this->writeAudit(['stalled' => 0, 'synced_without_erp_id' => 0, 'erp_id_without_synced' => 0, 'missing_in_erp' => 0, 'deleted_in_erp' => 5]);
        $row = collect((new SystemHealthReport)->rows())->firstWhere('key', 'board_sync_stalled');

        $this->assertTrue($row['ok'], 'ERP 에서 지운 차는 전송 실패가 아니다');
        $this->assertStringContainsString('5', $row['detail']);
    }

    public function test_counts_are_copied_not_judged(): void
    {
        $this->writeAudit(['stalled' => 2, 'synced_without_erp_id' => 1, 'erp_id_without_synced' => 3, 'missing_in_erp' => 1]);
        $rows = $this->rows();

        $this->assertFalse($rows['board_sync_stalled']['ok']);
        $this->assertStringContainsString('2', $rows['board_sync_stalled']['detail']);
        $this->assertStringContainsString('1', $rows['board_sync_stalled']['detail']);

        $this->assertFalse($rows['board_sync_integrity']['ok']);
        $this->assertStringContainsString('3', $rows['board_sync_integrity']['detail']);
    }

    public function test_a_stale_audit_file_is_flagged_so_a_dead_command_cannot_look_healthy(): void
    {
        $this->writeAudit(['stalled' => 0, 'synced_without_erp_id' => 0, 'erp_id_without_synced' => 0, 'missing_in_erp' => 0], ageDays: 3);
        $rows = $this->rows();
        $this->assertFalse($rows['board_sync_stalled']['ok']);
        $this->assertStringContainsString('3', $rows['board_sync_stalled']['detail']);
        $this->assertFalse($rows['board_sync_integrity']['ok']);
    }

    public function test_failed_erp_check_is_an_x_not_a_zero(): void
    {
        $this->writeAudit(['stalled' => 0, 'synced_without_erp_id' => 0, 'erp_id_without_synced' => 0, 'missing_in_erp' => 0], missing: null, errors: ['ERP 401']);
        $row = $this->rows()['board_sync_stalled'];
        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('401', $row['detail']);
    }

    public function test_broken_json_is_flagged(): void
    {
        File::put($this->path, '{not json');
        $this->assertFalse($this->rows()['board_sync_stalled']['ok']);
    }
}
