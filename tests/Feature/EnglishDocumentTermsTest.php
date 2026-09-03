<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 바이어·세관이 받는 **영문 서류에 한글이 남지 않게** (jin 2026-09-03).
 *
 * 실측으로 세 군데가 한글을 그대로 찍고 있었다:
 *   ① 판매 인보이스 Code = `62거4485`(한글 번호판) — 판매계약서는 2026-07-29 부터 로마자였는데 안 따라왔다
 *   ② 판매 인보이스 Maker · RORO/컨테이너 **계약서** Maker = 한글 브랜드
 *      → 같은 선적 건인데 **Invoice&Packing 은 영문, Contract 는 한글**로 갈렸다
 *   ③ 통관 영문등록증 연료 = `HYBRID(GASOLINE+전기)` (§8 #75-C, 실측 39/254 = 15%)
 *
 * ⚠️ 전부 **서류는 정상 생성되고 칸 값만 한글**이라 기능 테스트로는 원리상 안 잡힌다.
 *    그래서 ①②는 **생성물의 셀**을 읽고, 재발은 **정적 검사**로 막는다.
 */
class EnglishDocumentTermsTest extends TestCase
{
    use RefreshDatabase;

    /** 외국인이 받는 서류의 매핑 — 여기엔 가공 안 된 한글 필드가 들어가면 안 된다. */
    private const FOREIGN_DOC_MAPPINGS = [
        'SalesInvoiceMapping',
        'SalesContractMapping',
        'RoroContractMapping',
        'ContainerContractMapping',
        'RoroInvoicePackingMapping',
        'ContainerInvoicePackingMapping',
    ];

    private function vehicle(array $o = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '62거4485', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1438, 'dhl_request' => false,
            'brand' => '르노(삼성)', 'model_type' => 'SM6',
            'nice_reg_vin' => 'WAUZZZ4GXGN013468',
            'nice_reg_fuel_type' => '하이브리드(경유+전기)',
            'sale_price' => 14730, 'shipping_method' => 'RORO',
        ], $o));
    }

    // ── ① · ② 생성물 확인 ────────────────────────────────────────────

    public function test_sales_invoice_prints_romanized_plate_and_english_brand(): void
    {
        config(['company.template_set' => 'heyman']);
        $sheet = (new DocumentFiller($this->vehicle()))->spreadsheet('invoice')->getSheetByName('Invoice');

        $this->assertSame('62GEO4485', (string) $sheet->getCell('A18')->getValue(),
            '판매 인보이스 Code 가 한글 번호판이다 — 바이어가 못 읽는다');
        $this->assertSame('Renault Samsung', (string) $sheet->getCell('B18')->getValue(),
            '판매 인보이스 Maker 가 한글 브랜드다');
    }

    /**
     * 🚨 브랜드 표는 **키가 중복되면 뒤엣것이 조용히 이긴다** — 예외도 경고도 없다.
     * 실제로 이 세션에서 `르노(삼성)` 을 두 번 적어 앞의 정의가 통째로 무시됐다.
     */
    public function test_brand_map_has_no_duplicate_keys(): void
    {
        $src = file_get_contents(app_path('Services/Documents/DocValue.php'));
        $this->assertSame(1, preg_match('/function brandEn.*?return \[(.*?)\]\[/s', $src, $m),
            'brandEn 의 매핑 배열을 못 찾았다 — 구조가 바뀌었으면 이 검사도 같이 고칠 것');

        preg_match_all("/'([^']+)'\s*=>/u", $m[1], $keys);
        $dup = array_keys(array_filter(array_count_values($keys[1]), fn ($n) => $n > 1));

        $this->assertSame([], $dup, '브랜드 매핑에 중복 키: '.implode(', ', $dup));
        $this->assertGreaterThan(30, count($keys[1]), '브랜드 매핑이 통째로 사라졌다');
    }

    public function test_shipping_contracts_print_english_brand_like_their_invoice(): void
    {
        // 같은 선적 건의 Invoice&Packing 은 이미 영문이었다 — 계약서만 한글이라 두 장이 갈렸다.
        config(['company.template_set' => 'heyman']);

        foreach (['roro_contract', 'container_contract'] as $type) {
            $sheet = (new DocumentFiller($this->vehicle()))->spreadsheet($type)->getSheetByName('HBB340.');
            $this->assertSame('Renault Samsung', (string) $sheet->getCell('B16')->getValue(),
                "{$type} Maker 가 한글 브랜드다");
        }
    }

    /** `르노(삼성)` 은 괄호 때문에 `르노삼성` 매핑으로 안 잡혔다 — 실측 heymanerp 3대. */
    public function test_brand_map_covers_the_korean_values_actually_in_production(): void
    {
        // 운영 실측 3사 한글 브랜드 = 이 5종이 전부(heymanerp 9대 + ssancarerp 1대).
        //   `르노(삼성)`·`르노삼성` = **Renault Samsung**(jin 2026-09-03). 둘을 다르게 두면 같은 브랜드가 갈린다.
        $cases = [
            '르노(삼성)' => 'Renault Samsung', '르노삼성' => 'Renault Samsung',
            '벤츠' => 'BENZ', '기아' => 'KIA', '아우디' => 'AUDI', '현대' => 'HYUNDAI',
            '르노' => 'RENAULT',   // 삼성 없는 르노는 종전대로
        ];

        foreach ($cases as $ko => $en) {
            $this->assertSame($en, DocValue::brandEn(new Vehicle(['brand' => $ko])),
                "운영에 실재하는 한글 브랜드 '{$ko}' 가 영문으로 안 바뀐다");
        }

        // 이미 영문인 값은 그대로 통과해야 한다(적재분 대부분).
        $this->assertSame('BENZ', DocValue::brandEn(new Vehicle(['brand' => 'BENZ'])));
    }

    // ── ③ 연료 — 영문등록증 cascade ──────────────────────────────────

    public function test_clearance_fuel_converts_every_production_value(): void
    {
        // 운영 실측 3사 전량. 괄호·동의어 때문에 옛 SUBSTITUTE 가 15% 를 놓쳤다.
        $cases = [
            '경유' => 'DIESEL', '디젤' => 'DIESEL',
            '휘발유' => 'GASOLINE', '휘발유(무연)' => 'GASOLINE', '가솔린' => 'GASOLINE',
            '하이브리드(경유+전기)' => 'HYBRID', '하이브리드(휘발유+전기)' => 'HYBRID',
            '엘피지' => 'LPG', '전기' => 'ELECTRIC',
        ];

        config(['company.template_set' => 'heyman']);

        foreach ($cases as $ko => $en) {
            $ss = (new DocumentFiller($this->vehicle(['nice_reg_fuel_type' => $ko])))->spreadsheet('clearance');

            $this->assertSame($en, (string) $ss->getSheetByName('영문등록증')->getCell('D31')->getCalculatedValue(),
                "연료 '{$ko}' 가 영문등록증에서 영문이 아니다 — 양식에 옛 수식이 되살아났는지 확인할 것");
            $this->assertSame($ko, (string) $ss->getSheetByName('한글등록증')->getCell('D31')->getCalculatedValue(),
                '한글등록증 연료는 원본 그대로여야 한다');
        }
    }

    /** ⚠️ 하이브리드가 DIESEL/GASOLINE 으로 새면 안 된다 — 판정 순서가 뒤집히면 조용히 틀린다. */
    public function test_hybrid_is_decided_before_its_component_fuels(): void
    {
        foreach (['하이브리드(경유+전기)', '하이브리드(휘발유+전기)'] as $ko) {
            $this->assertSame('HYBRID',
                DocValue::fuelEn(new Vehicle(['nice_reg_fuel_type' => $ko])));
        }
    }

    // ── 재발 방지 — 정적 검사 ────────────────────────────────────────

    public function test_no_foreign_document_mapping_uses_raw_korean_fields(): void
    {
        foreach (self::FOREIGN_DOC_MAPPINGS as $class) {
            $path = app_path("Services/Documents/Mappings/{$class}.php");
            $this->assertFileExists($path);
            $src = file_get_contents($path);

            // 로마자 변환을 거친 것은 정상 — 그것만 지우고 남은 raw 사용을 잡는다.
            $stripped = preg_replace('/romanizePlate\(\s*\$v->vehicle_number\s*\)/', '', $src);

            $this->assertStringNotContainsString('$v->vehicle_number', $stripped,
                "{$class} 이 한글 번호판을 그대로 쓴다 — 외국인 서류엔 DocValue::romanizePlate() 를 쓸 것");
            $this->assertStringNotContainsString('$v->brand', $src,
                "{$class} 이 한글 브랜드를 그대로 쓴다 — DocValue::brandEn() 을 쓸 것");
        }
    }
}
