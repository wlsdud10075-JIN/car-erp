<?php

namespace Tests\Feature;

use App\Http\Controllers\VehicleDocumentController;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\Mappings\TransferCertificateMapping;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * 자동차양도증명서(별지 제16호서식) — 서류탭 「매입」 (jin 2026-09-11 사양 확정).
 *
 * 이 부류는 **기능 테스트로 원리상 못 잡는다** — 서류는 늘 정상 생성되고 칸만 비거나 틀린다.
 * 그래서 매핑 배열이 아니라 **생성물의 셀을 실제로 읽어** 확인한다(SKILLS §8 #37).
 *
 * 지키는 것:
 *  ① 회사정보가 세트별로 갈린다 — karaba 에 싼카 매매업자 등록번호가 새지 않는다(§8 #75).
 *  ② **노란 배경이 하나도 안 남는다** — 매핑 좌표를 흰칸에 잡으면 인쇄물에 노란칸이 남는다(§8 #71).
 *  ③ 값 없는 차는 **빈칸** — 없는 값을 만들어 채우지 않는다. 3세트 전부(§8 #75-B).
 *  ④ 금액·날짜 서식이 살아 있다 — 없으면 `12000000`·`46243` 같은 생숫자가 인쇄된다.
 *  ⑤ 도장 슬롯이 **3사 전부** 있다 — `heymanSlots()` 가 전량 복사본이라 빠뜨리기 쉽다.
 *  ⑥ 국내 서류라 `EXPORT_ONLY_TYPES` 가 아니다 — KRW 차량에서도 나와야 한다.
 */
class TransferCertificateDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const TYPE = 'transfer_certificate';

    private const SHEET = '3.양도증명서';

    private const SETS = ['system', 'heyman', 'karaba'];

    /** 빌더 상수와 같은 값. 여기서 한 번 더 못박아 양식이 조용히 바뀌면 실패시킨다. */
    private const COMPANY = [
        'system' => ['name' => '주식회사 싼카', 'dealer' => '02-4115-000476', 'ceo' => '조태신', 'agent' => '이기용'],
        'heyman' => ['name' => '주식회사 헤이맨', 'dealer' => '', 'ceo' => '조태신', 'agent' => '조태신'],
        'karaba' => ['name' => '주식회사 카라바', 'dealer' => '', 'ceo' => '김희철', 'agent' => '김희철'],
    ];

    private function vehicle(array $overrides = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '19더9065',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'model_type' => 'A6 50 TDI quattro',
            'nice_reg_vin' => 'WAUZZZ4GXGN013468',
            'nice_reg_vehicle_form' => '승용 중형',
            'nice_reg_owner_name' => '박영록',
            'nice_reg_owner_addr' => '경기도 평택시 현신3길 33-37, 206호(용이동)',
            'mileage' => 33816,
            'year' => 2022,
            'purchase_price' => 6450000,
        ], $overrides));
    }

    private function sheetFor(string $set, Vehicle $v): Worksheet
    {
        config(['company.template_set' => $set]);

        return (new DocumentFiller($v))->spreadsheet(self::TYPE)->getSheetByName(self::SHEET);
    }

    // ── ① 회사정보 ────────────────────────────────────────────────────

    public function test_company_block_differs_per_company(): void
    {
        foreach (self::SETS as $set) {
            $sh = $this->sheetFor($set, $this->vehicle());
            $co = self::COMPANY[$set];

            // 양수인(을) = 회사. 갑이 아니다 — 방향이 뒤집히면 서류가 통째로 틀린다.
            $this->assertSame($co['name'], trim((string) $sh->getCell('AD5')->getValue()), "{$set} 양수인 상호");
            $this->assertSame($co['name'], trim((string) $sh->getCell('X8')->getValue()), "{$set} 매매업자 상호");
            $this->assertSame($co['dealer'], trim((string) $sh->getCell('H8')->getValue()), "{$set} 매매업자 등록번호");
            $this->assertSame($co['ceo'], trim((string) $sh->getCell('H9')->getValue()), "{$set} 대표자");
            $this->assertSame($co['agent'], trim((string) $sh->getCell('H10')->getValue()), "{$set} 취급자");
        }
    }

    public function test_karaba_does_not_carry_the_ssancar_dealer_number(): void
    {
        // 🚨 karaba 는 다른 양식 4곳에 싼카 값이 남아 있던 전력이 있다(SKILLS §8 #75). 여기로 번지지 않게 못박는다.
        $sh = $this->sheetFor('karaba', $this->vehicle());

        foreach ($sh->getCoordinates() as $coord) {
            $val = (string) $sh->getCell($coord)->getValue();
            $this->assertStringNotContainsString('02-4115-000476', $val, "karaba 에 싼카 매매업자번호가 남았다: {$coord}");
            $this->assertStringNotContainsString('주식회사 싼카', $val, "karaba 에 싼카 상호가 남았다: {$coord}");
        }
    }

    // ── ② 노란 배경 잔존 0 ────────────────────────────────────────────

    public function test_no_yellow_fill_survives_generation(): void
    {
        // 매핑 좌표를 흰칸에 잡으면 `clearYellowFill` 이 그 칸을 못 지워 **인쇄물에 노란 배경**이 남는다.
        // 반대로 고정 리터럴을 노란칸에 두면 기입 전에 비워져 공란이 된다 — 그건 아래 ③ 이 잡는다.
        foreach (self::SETS as $set) {
            $sh = $this->sheetFor($set, $this->vehicle());

            foreach ($sh->getCoordinates() as $coord) {
                $fill = $sh->getStyle($coord)->getFill();
                $solidYellow = $fill->getFillType() === Fill::FILL_SOLID
                    && $fill->getStartColor()->getARGB() === 'FFFFFF00';
                $this->assertFalse($solidYellow, "{$set} 생성물에 노란칸이 남았다: {$coord}");
            }
        }
    }

    public function test_every_mapped_cell_is_yellow_in_every_template(): void
    {
        // 매핑이 쓰는 칸은 양식에서 노란칸이어야 한다. 빌더가 매핑 상수를 읽어 칠하므로
        // 어긋날 일이 없지만, 좌표를 손으로 옮겨 적는 회귀를 막는다.
        foreach (self::SETS as $set) {
            $path = resource_path("templates/{$set}/transfer_certificate.xlsx");
            $sh = IOFactory::createReader('Xlsx')->load($path)->getSheetByName(self::SHEET);

            foreach (TransferCertificateMapping::CELLS as $name => $coord) {
                $fill = $sh->getStyle($coord)->getFill();
                $this->assertSame(Fill::FILL_SOLID, $fill->getFillType(), "{$set} {$name}({$coord}) 이 노란칸이 아니다");
                $this->assertSame('FFFFFF00', $fill->getStartColor()->getARGB(), "{$set} {$name}({$coord}) 색이 다르다");
            }
        }
    }

    // ── 기입 ──────────────────────────────────────────────────────────

    public function test_it_is_filled_from_erp(): void
    {
        $v = $this->vehicle();
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'type' => 'down', 'amount' => 2000000,
            'payment_date' => '2026-09-07', 'confirmed_at' => now(),
        ]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 4450000,
            'payment_date' => '2026-09-20', 'confirmed_at' => now(),
        ]);

        $sh = $this->sheetFor('system', $v->fresh());

        $this->assertSame('박영록', $sh->getCell('L5')->getValue());
        $this->assertSame('경기도 평택시 현신3길 33-37, 206호(용이동)', $sh->getCell('L7')->getValue());
        $this->assertSame('19더9065', $sh->getCell('F13')->getValue());
        $this->assertSame(33816, $sh->getCell('Y13')->getValue());
        $this->assertSame('A6 50 TDI quattro', $sh->getCell('Y14')->getValue());
        $this->assertSame('WAUZZZ4GXGN013468', $sh->getCell('F15')->getValue());
        $this->assertSame(2000000, $sh->getCell('AE15')->getValue());
        $this->assertSame(4450000, $sh->getCell('AE16')->getValue());
        $this->assertSame(6450000, $sh->getCell('F17')->getValue());
    }

    public function test_the_vehicle_form_cell_carries_both_the_kind_and_the_year(): void
    {
        // jin 2026-09-12 — 기입 예시 실물이 「승용  2022」 다. NICE 는 `승용 중형` 이라 **첫 토큰만** 쓴다.
        $sh = $this->sheetFor('system', $this->vehicle());
        $this->assertSame('승용 2022', $sh->getCell('F14')->getValue());

        // 🚨 운영 표기가 **순서·띄어쓰기까지 섞여 있다**(SKILLS §8 #75-C) — 전부 같은 결과여야 한다.
        //    첫 토큰을 자르면 `중형 승용` 인 차가 「중형 2022」로 인쇄된다.
        foreach (['중형 승용' => '승용 2022', '중형승용' => '승용 2022', '승합 중형' => '승합 2022',
            '화물 대형' => '화물 2022', '특수' => '특수 2022'] as $raw => $want) {
            $sh = $this->sheetFor('system', $this->vehicle([
                'vehicle_number' => '19더90'.random_int(10, 99), 'nice_reg_vehicle_form' => $raw,
            ]));
            $this->assertSame($want, $sh->getCell('F14')->getValue(), "차종 표기 '{$raw}'");
        }

        // 모르는 표기는 원본 그대로 — 위장하지 않는다(실측 쓰레기값 `205 004` 가 존재한다).
        $sh = $this->sheetFor('system', $this->vehicle([
            'vehicle_number' => '19더9067', 'nice_reg_vehicle_form' => '205 004',
        ]));
        $this->assertSame('205 004 2022', $sh->getCell('F14')->getValue());

        // 차종 정보가 없으면 연식만.
        $sh = $this->sheetFor('system', $this->vehicle([
            'vehicle_number' => '19더9066', 'nice_reg_vehicle_form' => null,
        ]));
        $this->assertSame('2022', $sh->getCell('F14')->getValue());
    }

    public function test_only_confirmed_purchase_payments_are_printed(): void
    {
        // 미확정 잔금은 「지급했다」가 아니다 — 미수·정산과 같은 기준(SKILLS §13).
        $v = $this->vehicle();
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'type' => 'down', 'amount' => 2000000,
            'payment_date' => '2026-09-07', 'confirmed_at' => null,
        ]);

        $sh = $this->sheetFor('system', $v->fresh());

        $this->assertNull($sh->getCell('AE15')->getValue(), '미확정 계약금이 인쇄됐다');
        $this->assertNull($sh->getCell('Y15')->getValue(), '미확정 계약금 날짜가 인쇄됐다');
    }

    // ── ③ 없는 값은 빈칸 ──────────────────────────────────────────────

    public function test_missing_data_leaves_blanks_rather_than_inventing_values(): void
    {
        // 흰칸 샘플 리터럴이 남아 있으면 「그럴듯한 거짓값」이 인쇄된다(SKILLS §8 #71).
        // 3세트 전부 확인한다 — 한 세트만 보면 나머지의 잔재를 놓친다(§8 #75-B).
        $bare = $this->vehicle([
            'vehicle_number' => '99바9999',
            'model_type' => null, 'nice_reg_vin' => null, 'nice_reg_vehicle_form' => null,
            'nice_reg_owner_name' => null, 'nice_reg_owner_addr' => null,
            'mileage' => null, 'year' => null, 'purchase_price' => 0,
        ]);

        foreach (self::SETS as $set) {
            $sh = $this->sheetFor($set, $bare);

            foreach (TransferCertificateMapping::CELLS as $name => $coord) {
                if ($name === 'plate') {
                    continue;   // 차량번호는 항상 있다
                }
                $this->assertSame('', (string) $sh->getCell($coord)->getValue(),
                    "{$set} {$name}({$coord}) 에 값이 없는데 뭔가 인쇄된다");
            }
        }
    }

    public function test_blank_fields_that_the_company_fills_by_hand_stay_empty(): void
    {
        // jin 확정 — 계약연월일·자동차인도일·제시/매도번호는 공란, 압류·저당은 사람이 원부를 보고 적는다.
        // 🚫 원부조회(`CarmodooService`)는 모달 표시 전용이라 DB 에 안 남는다. 「0」을 찍으면 거짓말이 된다.
        $sh = $this->sheetFor('system', $this->vehicle());

        foreach (['K4' => '제시/매도 번호', 'F20' => '자동차인도일', 'Y20' => '압류 및 저당권 등록여부'] as $coord => $label) {
            $this->assertSame('', (string) $sh->getCell($coord)->getValue(), "{$label} 는 공란이어야 한다");
        }
    }

    // ── ④ 서식 ────────────────────────────────────────────────────────

    public function test_money_and_date_cells_keep_their_number_format(): void
    {
        // 서식이 없으면 `12000000`·`46243`(엑셀 날짜 일련번호)이 그대로 인쇄된다.
        foreach (self::SETS as $set) {
            $sh = $this->sheetFor($set, $this->vehicle());

            foreach (['AE15', 'AE16', 'F17', 'L16'] as $coord) {
                $this->assertStringContainsString('원정', $sh->getStyle($coord)->getNumberFormat()->getFormatCode(),
                    "{$set} {$coord} 금액 서식이 사라졌다");
            }
            foreach (['Y15', 'Y16'] as $coord) {
                $this->assertStringContainsString('년', $sh->getStyle($coord)->getNumberFormat()->getFormatCode(),
                    "{$set} {$coord} 날짜 서식이 사라졌다");
            }
        }
    }

    // ── ⑤ 도장 ────────────────────────────────────────────────────────

    public function test_every_company_has_stamp_slots(): void
    {
        // 🚨 `heymanSlots()` 는 전량 복사본이라 `defaultSlots()` 에만 넣으면 heyman 만 조용히 빠진다.
        foreach (self::SETS as $set) {
            $slots = StampSlots::for(self::TYPE, $set);
            $this->assertNotEmpty($slots, "{$set} 에 양도증명서 도장 슬롯이 없다");
            $this->assertSame(['dealer_seal', 'assignee_seal'], array_column($slots, 'key'), "{$set} 슬롯 구성");

            foreach ($slots as $slot) {
                $this->assertSame(self::SHEET, $slot['sheet'], "{$set} 슬롯 시트명");
                $this->assertArrayNotHasKey('exact', $slot, "{$set} 는 exact 를 쓰면 도장이 찌그러진다");
            }
        }
    }

    public function test_the_seal_appears_only_after_it_is_uploaded(): void
    {
        Storage::fake('local');

        foreach (self::SETS as $set) {
            // 업로드 전 — 백지 양식이라 baked 도장이 없다.
            $sh = $this->sheetFor($set, $this->vehicle());
            $this->assertSame(0, $sh->getDrawingCollection()->count(), "{$set} 도장 없이 그림이 생겼다");
        }
    }

    public function test_the_document_type_is_labelled_in_korean(): void
    {
        // 라벨이 없으면 기능설정 「도장 슬롯」 화면에 키 문자열이 그대로 찍힌다(SKILLS §8 #41).
        $this->assertSame('양도증명서', StampSlots::DOC_LABELS[self::TYPE] ?? null);
    }

    // ── ⑥ 라우트 · 채널 ───────────────────────────────────────────────

    public function test_route_serves_the_document(): void
    {
        $user = User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('erp.vehicles.documents.show', ['id' => $this->vehicle()->id, 'type' => self::TYPE]))
            ->assertOk();
    }

    public function test_it_is_a_domestic_document_not_an_export_only_one(): void
    {
        // 🚫 `EXPORT_ONLY_TYPES` 에 넣지 말 것 — 국내 서류다(jin 2026-09-11).
        //
        // ⚠️ **라우트를 태워서는 못 잡는다.** 그 게이트는 `sales_channel === 'export'` 를 보는데
        //    운영 enum 이 `ENUM('export')` **단일값**이라 원리상 발동하지 않는다(SKILLS 「판매채널」).
        //    실제로 이 목록에 넣고 KRW 차량으로 요청해 봤더니 **그대로 200** 이었다.
        //    ⇒ 목록 자체를 정적으로 본다. 그래야 채널이 다시 늘어나는 날에도 의도가 지켜진다.
        $ref = new \ReflectionClass(VehicleDocumentController::class);

        $this->assertContains(self::TYPE, $ref->getConstant('SUPPORTED_TYPES'), '지원 목록에 없으면 404 다');
        $this->assertNotContains(self::TYPE, $ref->getConstant('EXPORT_ONLY_TYPES'), '국내 서류인데 수출 전용으로 묶였다');
        $this->assertNotContains(self::TYPE, $ref->getConstant('MULTI_TYPES'), '다중차량 서류가 아니다');
    }

    public function test_a_domestic_currency_vehicle_can_download_it(): void
    {
        $user = User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $krw = $this->vehicle(['vehicle_number' => '12가3456', 'currency' => 'KRW']);

        $this->actingAs($user)
            ->get(route('erp.vehicles.documents.show', ['id' => $krw->id, 'type' => self::TYPE]))
            ->assertOk();
    }
}
