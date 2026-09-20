<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SettlementExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * 📄 **정산 엑셀 — A열 No. · 마진율 · 환차** (jin 2026-09-18).
 *
 * jin: *「엑셀 내려받기 하면 맨 앞열에 a열1번셀 No. a열2번셀 숫자 1,2,3,4 이런 숫자가 가운데정렬로
 *        있으면 좋겠어. 넘버링이 인크리먼트로 생기는것이지 셀마다.」* → *「응 시트마다 그래야지」*
 *      *「기타공제가 환차인거같은데 … 환차에 기입이 되거나 이전달 환차는 이월(받음) 여기에 표시되어야」*
 *
 * 🔑 엑셀이 **화면과 같은 단일 출처**를 쓰는지가 이 테스트의 본체다 — 식을 옮겨 적으면
 *    「화면 3.6% ↔ 엑셀 3.7%」가 되고, 그건 사람이 눈으로 못 잡는다(§8 #44·#45).
 */
class SettlementExportMarginTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    /** 외화 차량 + 확정 정산. `$paid` 면 완납시킨다(환차를 말할 수 있는 상태). */
    private function settlement(Salesman $sm, int $salePrice, int $purchase, bool $paid, array $attrs = []): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'EX'.++$this->c,
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1000,
            'salesman_id' => $sm->id, 'purchase_price' => $purchase, 'purchase_date' => '2026-07-01',
            'sale_price' => $salePrice, 'sale_date' => '2026-07-02',
        ]);
        if ($paid) {
            $v->finalPayments()->create([
                'type' => 'balance', 'amount' => $salePrice, 'exchange_rate' => 1100,
                'payment_date' => '2026-07-10', 'confirmed_at' => now(),
            ]);
            $v->refresh();
        }

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-07-15',
            'attributed_month' => '2026-07-01',
        ], $attrs));
    }

    private function salesman(string $name): Salesman
    {
        return Salesman::firstOrCreate(['name' => $name], ['type' => 'freelance', 'is_active' => true]);
    }

    private function headerIndex(Worksheet $sheet, string $label): int
    {
        for ($c = 1; $c <= 60; $c++) {
            if ((string) $sheet->getCell([$c, 1])->getValue() === $label) {
                return $c;
            }
        }
        $this->fail("엑셀에 「{$label}」 열이 없다");
    }

    // ── A열 No. ────────────────────────────────────────────────────────

    /** 🔢 **시트마다 1부터** — 요약 시트도 포함. 가운데 정렬. */
    public function test_every_sheet_numbers_its_rows_from_one(): void
    {
        $sm = $this->salesman('조하');
        $this->settlement($sm, 10_000, 5_000_000, true);
        $this->settlement($sm, 20_000, 9_000_000, true);
        $this->settlement($this->salesman('와심'), 8_000, 3_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());

        foreach ($book->getAllSheets() as $sheet) {
            $this->assertSame('No.', (string) $sheet->getCell('A1')->getValue(),
                "{$sheet->getTitle()} 시트의 A1 이 No. 가 아니다");
            $this->assertSame(1, (int) $sheet->getCell('A2')->getValue(),
                "{$sheet->getTitle()} 시트가 1번부터 시작하지 않는다");
            $this->assertSame(Alignment::HORIZONTAL_CENTER,
                $sheet->getStyle('A2')->getAlignment()->getHorizontal(), 'No. 가 가운데 정렬이 아니다');
        }

        // 조하 시트는 2건 → 1,2 가 차례로. 합계 행의 A 는 비어 있다(번호 자리에 글자가 들어가면 정렬이 깨진다).
        $jo = $book->getSheetByName('조하');
        $this->assertSame(1, (int) $jo->getCell('A2')->getValue());
        $this->assertSame(2, (int) $jo->getCell('A3')->getValue());
        $this->assertSame('', (string) $jo->getCell('A4')->getValue(), '합계 행 번호 자리에 글자가 들어갔다');
        $this->assertStringContainsString('합계', (string) $jo->getCell('B4')->getValue());
    }

    // ── 마진율 ──────────────────────────────────────────────────────────

    /** 차량 줄의 마진율은 화면과 **같은 값**이다. */
    public function test_the_vehicle_row_margin_rate_matches_the_model(): void
    {
        $s = $this->settlement($this->salesman('조하'), 10_000, 5_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '마진율');

        $this->assertEqualsWithDelta($s->margin_rate, (float) $sheet->getCell([$col, 2])->getValue(), 1e-9);
        $this->assertSame('0.0%', $sheet->getStyle([$col, 2])->getNumberFormat()->getFormatCode(),
            '비율이 % 서식 없이 들어갔다 — 0.04 로 보인다');
    }

    /**
     * 🚨 **합계 행은 평균도 SUM 도 아니다** — Σ총마진 ÷ Σ판매금원화.
     *    금액이 100배 차이나는 두 대를 넣어 평균과 확실히 갈리게 한다.
     */
    public function test_the_footer_margin_rate_is_weighted(): void
    {
        $sm = $this->salesman('조하');
        $a = $this->settlement($sm, 10_000, 9_000_000, true);
        $b = $this->settlement($sm, 100, 10_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '마진율');

        $expected = Settlement::marginRateOf(collect([$a, $b]));
        $average = ($a->margin_rate + $b->margin_rate) / 2;

        $footer = (float) $sheet->getCell([$col, 4])->getValue();   // 2·3행이 데이터, 4행이 합계
        $this->assertEqualsWithDelta($expected, $footer, 1e-9, '합계 마진율이 가중이 아니다');
        $this->assertGreaterThan(0.2, abs($average - $expected), '표본이 평균과 안 갈려 검사가 무의미하다');
        $this->assertNotSame('', (string) $sheet->getCell([$col, 4])->getValue());
    }

    /** 요약 시트에도 사람별 마진율 + 전체 마진율. */
    public function test_the_summary_sheet_carries_margin_rates(): void
    {
        $jo = $this->salesman('조하');
        $a = $this->settlement($jo, 10_000, 5_000_000, true);
        $b = $this->settlement($this->salesman('와심'), 8_000, 3_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('요약');
        $col = $this->headerIndex($sheet, '마진율');

        // 행 순서는 담당자명 정렬(sortKeys) — 값으로 찾는다.
        $seen = [];
        for ($r = 2; $r <= 4; $r++) {
            $seen[(string) $sheet->getCell([2, $r])->getValue()] = (float) $sheet->getCell([$col, $r])->getValue();
        }

        $this->assertEqualsWithDelta($a->margin_rate, $seen['조하'], 1e-9);
        $this->assertEqualsWithDelta($b->margin_rate, $seen['와심'], 1e-9);
        $this->assertEqualsWithDelta(
            Settlement::marginRateOf(collect([$a, $b])), $seen['합계'], 1e-9,
            '요약 시트 전체 합계가 가중 마진율이 아니다'
        );
    }

    // ── 환차 ────────────────────────────────────────────────────────────

    /**
     * 💱 **1차만 된 완납 건에도 환차가 찍힌다** — jin 이 지적한 그 자리.
     *    구현 전에는 2차 마감 컬럼만 읽어 **빈칸**이었다.
     */
    public function test_the_fx_column_is_filled_from_the_first_settlement(): void
    {
        $s = $this->settlement($this->salesman('조하'), 10_000, 5_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '환차');

        // 실입금 10,000×1100 = 11,000,000 / baseline 10,000×1000 = 10,000,000
        $this->assertEqualsWithDelta(1_000_000.0, (float) $sheet->getCell([$col, 2])->getValue(), 0.01);
        $this->assertEqualsWithDelta(
            (float) $s->display_exchange_difference, (float) $sheet->getCell([$col, 2])->getValue(), 0.01,
            '엑셀이 화면과 다른 환차를 말한다'
        );
    }

    /** 🚨 미완납은 **빈칸** — 미리보기 식은 덜 받은 돈을 마이너스로 뱉는다(미수가 환차로 둔갑). */
    public function test_an_unpaid_vehicle_leaves_the_fx_cell_empty(): void
    {
        $this->settlement($this->salesman('조하'), 10_000, 5_000_000, false);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '환차');

        $this->assertSame('', (string) $sheet->getCell([$col, 2])->getValue(),
            '미완납 차에 환차가 찍혔다 — 미수가 환차로 둔갑한다');
    }

    /** 🚫 마진율은 하단 SUM 대상이 아니다 — 비율을 더하면 무의미한 숫자가 된다. */
    public function test_the_margin_rate_is_never_summed(): void
    {
        $sm = $this->salesman('조하');
        $this->settlement($sm, 10_000, 5_000_000, true);
        $this->settlement($sm, 20_000, 9_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '마진율');

        $this->assertStringNotContainsString('SUM', (string) $sheet->getCell([$col, 4])->getValue(),
            '마진율 합계가 SUM 수식이다');
    }

    // ── 로그인 경로 ─────────────────────────────────────────────────────

    /** 실제 내려받기 경로에도 새 열이 들어간다(감사 로그의 컬럼 목록 포함). */
    public function test_the_download_route_lists_the_new_columns(): void
    {
        $this->settlement($this->salesman('조하'), 10_000, 5_000_000, true);
        $user = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);

        $this->actingAs($user)->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();

        $labels = (new SettlementExportService)->columnLabels();
        $this->assertSame('No.', $labels[0], 'No. 가 맨 앞 열이 아니다');
        $this->assertContains('마진율', $labels);
    }

    // ── 숫자 쉼표 서식 ──────────────────────────────────────────────────

    /**
     * 💰 **금액 칸에 쉼표** (jin 2026-09-20 「그냥 숫자만 나온곳에 쉼표스타일을 넣어주는게」).
     *    마진율(%)·날짜·문자는 그대로 두고, **No. 순번에도 안 붙인다**(「1,024번」이 되면 안 된다).
     */
    public function test_money_cells_get_a_thousands_separator_but_labels_and_numbering_do_not(): void
    {
        $sm = $this->salesman('조하');
        $this->settlement($sm, 10_000, 5_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());

        foreach ([$book->getSheetByName('조하'), $book->getSheetByName('요약')] as $sheet) {
            $name = $sheet->getTitle();

            // No. 열엔 쉼표가 없다
            $this->assertStringNotContainsString(',', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode(),
                "{$name}: 순번에 쉼표 서식이 붙었다");

            $money = $this->headerIndex($sheet, $name === '요약' ? '총마진' : '총마진');
            $this->assertStringContainsString('#,##0',
                $sheet->getStyle([$money, 2])->getNumberFormat()->getFormatCode(),
                "{$name}: 총마진에 쉼표 서식이 없다");

            // 마진율은 % 그대로 (쉼표로 덮어쓰지 않았다)
            $rate = $this->headerIndex($sheet, '마진율');
            $this->assertSame('0.0%', $sheet->getStyle([$rate, 2])->getNumberFormat()->getFormatCode(),
                "{$name}: 마진율 서식이 쉼표로 덮였다");

            // 차량번호는 문자 — 서식을 건드리지 않았다
            if ($name !== '요약') {
                $plate = $this->headerIndex($sheet, '차량번호');
                $this->assertStringNotContainsString('#,##0',
                    $sheet->getStyle([$plate, 2])->getNumberFormat()->getFormatCode(), '문자 칸에 숫자 서식이 붙었다');
            }
        }
    }

    /**
     * 🚨 **정수 금액에 마침표가 매달리면 안 된다** (jin 2026-09-20
     *    「콤마는 맞는데 마침표는 왜 들어갔어..ㅋㅋ 마침표는 없애줘」).
     *
     *    엑셀은 `#,##0.##` 을 정수에 적용하면 **`1,234,567.`** 로 그린다 —
     *    **LibreOffice 는 안 붙여서** 렌더 확인만으로는 못 잡았다(엑셀 COM 실측 2026-09-20).
     *    ⇒ 소수 자리(`.`)가 서식에 들어가는 순간 재발한다. 그래서 **서식 코드 자체**를 못박는다.
     */
    public function test_money_formats_never_leave_a_dangling_decimal_point(): void
    {
        $sm = $this->salesman('조하');
        $this->settlement($sm, 10_000, 5_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());

        foreach ([$book->getSheetByName('조하'), $book->getSheetByName('요약')] as $sheet) {
            $name = $sheet->getTitle();
            foreach (['총마진', '정산액'] as $label) {
                $fmt = $sheet->getStyle([$this->headerIndex($sheet, $label), 2])->getNumberFormat()->getFormatCode();
                $this->assertStringNotContainsString('.', $fmt,
                    "{$name}/{$label}: 서식에 소수점이 있다 — 엑셀이 「1,234,567.」로 그린다");
                $this->assertSame('#,##0', $fmt);
            }
        }
    }

    /**
     * 💱 **환율만은 소수를 살린다** — `#,##0` 이면 JPY 8.6409 가 「9」가 되어 뜻이 사라진다.
     *    `General` 은 쉼표가 없지만 환율은 네 자리라 필요 없고, 마침표도 안 매단다.
     */
    public function test_the_exchange_rate_keeps_its_decimals(): void
    {
        $sm = $this->salesman('조하');
        $s = $this->settlement($sm, 10_000, 5_000_000, true);
        $s->vehicle->update(['exchange_rate' => 8.6409]);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $fmt = $sheet->getStyle([$this->headerIndex($sheet, '환율'), 2])->getNumberFormat()->getFormatCode();

        $this->assertSame('General', $fmt, '환율 서식이 바뀌었다 — JPY 가 「9」로 보일 수 있다');
        $this->assertSame('8.6409', NumberFormat::toFormattedString(8.6409, $fmt));
        $this->assertSame('1621', NumberFormat::toFormattedString(1621, $fmt), '정수 환율에 군더더기가 붙었다');
    }

    /**
     * 🐢 서식은 **열 단위로 한 번씩** 건다 — 셀마다 걸면 4,500행에서 내려받기가 느려진다.
     *    관찰 가능한 증거 = 값이 없는 행(합계 행 아래 빈 칸)까지 같은 서식이 걸려 있는가.
     */
    public function test_the_format_is_applied_to_the_column_including_the_footer_row(): void
    {
        $sm = $this->salesman('조하');
        $this->settlement($sm, 10_000, 5_000_000, true);
        $this->settlement($sm, 20_000, 9_000_000, true);

        $book = (new SettlementExportService)->build(Settlement::with('vehicle', 'salesman')->get());
        $sheet = $book->getSheetByName('조하');
        $col = $this->headerIndex($sheet, '총마진');

        // 2·3 = 데이터, 4 = 합계(SUM 수식)
        foreach ([2, 3, 4] as $r) {
            $this->assertStringContainsString('#,##0',
                $sheet->getStyle([$col, $r])->getNumberFormat()->getFormatCode(),
                "{$r}행 총마진에 쉼표 서식이 없다");
        }
    }
}
