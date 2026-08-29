<?php

namespace Tests\Feature;

use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * `ssancarerp:import-settled --unsettled` (jin 2026-08-29).
 *
 * 이 명령은 「정산이 끝나 더 받을 돈도 줄 돈도 없는 과거분」 전제로 만들어졌다. 그래서
 * ① 미수를 0 으로 눕히고(ReceivableHistory 마커) ② 매입 차액을 잔금으로 채워 미지급 0 을 만들고
 * ③ 비고(CO)를 보고 정산을 paid+2차closed 로 박제한다.
 *
 * 미정산 파일 856 대는 정반대다 — 실측 465 대가 미납(USD 129만 + EUR 279만)이고 매입대기가 107 대다.
 * 위 셋을 그대로 태우면 **살아있는 채권·미지급이 조용히 사라진다**(예외도 로그도 없다).
 * ⇒ `--unsettled` 가 셋을 동시에 끈다. 하나만 꺼도 반쪽이라 플래그를 쪼개지 않았다.
 *
 * 그리고 운임 두 칸이 서로 다른 값이라는 것도 여기서 못박는다 —
 *   AP(운임비, 판매통화) → `transport_fee`  = 미수 분모·면장에 **들어간다**
 *   AQ(운임 USD,  기록칸) → `transport_fee_usd` = 어떤 계산에도 **안 들어간다**
 * 뒤바뀌면 3,839 대의 미수와 면장이 통째로 움직인다.
 */
class ImportSsancarUnsettledTest extends TestCase
{
    use RefreshDatabase;

    private const PURCHASE = 1_000_000;

    private const SALE = 10_000;

    private const FREIGHT_KRW = 1_000;      // AP — 판매통화

    private const FREIGHT_USD = 1_170;      // AQ — USD (같은 운임의 달러 표기)

    private const PAID = 4_000;             // AU 입금

    /** @param array<string,mixed> $override */
    private function fixture(array $override = []): string
    {
        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        $sh->setTitle('수출차량매입-2026');
        $cells = array_merge([
            'B' => '2026-02-01',            // 구입일
            'D' => 'TEST-1',                // 차량번호
            'E' => 'BMW', 'F' => 'X5', 'G' => 2020, 'H' => 50000,
            'L' => 'TESTVIN0000000001',     // 차대번호
            'M' => 'TESTMAN',               // 담당자
            'T' => self::PURCHASE,          // 구입금액
            'U' => 0,                       // 매도비
            'AC' => '2026-03-01',           // 선적일 → 출고일 복사
            'AF' => 'TEST BUYER',
            'AI' => 'USD', 'AJ' => self::SALE, 'AL' => 1300,
            'AP' => self::FREIGHT_KRW,
            'AQ' => self::FREIGHT_USD,
            'AU' => self::PAID, 'AV' => '2026-03-10',
            'CO' => 'YT_26.05.10 정산완료',   // 정산 생성 조건을 일부러 만족시킨다
        ], $override);
        foreach ($cells as $col => $val) {
            $sh->setCellValue($col.'3', $val);
        }
        $path = sys_get_temp_dir().'/imp_unsettled_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function runImport(array $opts): Vehicle
    {
        Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $path = $this->fixture($opts['fixture'] ?? []);
        unset($opts['fixture']);
        $this->artisan('ssancarerp:import-settled', array_merge(['path' => $path, '--apply' => true], $opts))
            ->assertExitCode(0);
        @unlink($path);

        return Vehicle::where('vehicle_number', 'TEST-1')->firstOrFail();
    }

    public function test_unsettled_keeps_the_receivable_alive(): void
    {
        $v = $this->runImport(['--unsettled' => true]);

        $this->assertSame(0, ReceivableHistory::where('vehicle_id', $v->id)->count(),
            '미정산인데 미수 정리 마커가 생겼다 — 채권관리에서 그 차가 사라진다');
        // 총판매가 = 판매가 + 운임비(판매통화) = 11,000 / 입금 4,000 → 미수 7,000
        $this->assertEqualsWithDelta(
            self::SALE + self::FREIGHT_KRW - self::PAID,
            (float) $v->sale_unpaid_amount, 0.01, '미수가 남지 않았다');
    }

    public function test_unsettled_keeps_the_purchase_payable_alive(): void
    {
        $v = $this->runImport(['--unsettled' => true]);

        $this->assertSame(0, $v->purchaseBalancePayments()->count(),
            '송금 메모가 없는데 매입 지급이 생겼다 — 안 준 돈이 준 것으로 기록된다');
        $this->assertEqualsWithDelta(self::PURCHASE, (float) $v->purchase_unpaid_amount, 0.01,
            '매입 미지급이 거짓으로 0 이 됐다');
    }

    public function test_unsettled_creates_no_settlement_even_when_the_memo_says_so(): void
    {
        $v = $this->runImport(['--unsettled' => true]);

        $this->assertSame(0, Settlement::where('vehicle_id', $v->id)->count(),
            'CO 비고가 「정산완료」여도 미정산 모드에서는 정산을 만들면 안 된다');
    }

    public function test_default_mode_still_zeroes_both_sides(): void
    {
        // 정산완료 3,839 대가 실제로 걸어온 경로 — 플래그 추가로 깨지지 않았음을 못박는다.
        $v = $this->runImport([]);

        $this->assertEqualsWithDelta(0, (float) $v->sale_unpaid_amount, 0.01, '미수 0 보정이 죽었다');
        $this->assertEqualsWithDelta(0, (float) $v->purchase_unpaid_amount, 0.01, '매입 미지급 0 보정이 죽었다');
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '정산 생성이 죽었다');
    }

    public function test_the_two_freight_columns_do_not_swap(): void
    {
        foreach ([['--unsettled' => true], []] as $opts) {
            $v = $this->runImport($opts);

            $this->assertEqualsWithDelta(self::FREIGHT_KRW, (float) $v->transport_fee, 0.01,
                'AP(판매통화 운임비)가 transport_fee 에 안 들어갔다');
            $this->assertSame(self::FREIGHT_USD, (int) $v->transport_fee_usd,
                'AQ(운임 USD)가 기록칸에 안 들어갔다');
            $this->assertNotEquals((float) $v->transport_fee, (float) $v->transport_fee_usd,
                '두 칸이 같은 값이면 어느 한쪽을 잘못 읽은 것이다');
            // 기록칸이라 총판매가(미수 분모)에는 절대 안 들어간다.
            $this->assertEqualsWithDelta(self::SALE + self::FREIGHT_KRW, (float) $v->sale_total_amount, 0.01,
                '운임비(USD)가 총판매가에 섞였다');
            Vehicle::query()->forceDelete();
        }
    }

    public function test_negative_freight_usd_is_dropped_not_stored(): void
    {
        // 정산완료본 `02고1463` 이 실제로 −100 이었다. 운임이 음수일 리 없다.
        $v = $this->runImport(['--unsettled' => true, 'fixture' => ['AQ' => -100]]);

        $this->assertNull($v->transport_fee_usd, '음수 운임(USD)이 그대로 저장됐다');
    }
}
