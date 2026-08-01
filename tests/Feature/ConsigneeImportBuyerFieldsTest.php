<?php

namespace Tests\Feature;

use App\Console\Commands\ImportConsignees;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\Salesman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 컨사이니 양식의 **바이어 상세 열**(M~R, jin 2026-08-01).
 *
 * 배경: 차량 적재양식도 컨사이니 양식도 바이어를 **이름만** 만들어서, 서류의 바이어 칸
 * (Invoice Email·판매계약서 Passport/Tel/Address)이 전부 공란으로 나갔다.
 */
class ConsigneeImportBuyerFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array<string,string>>  $rows */
    private function sheet(array $rows): string
    {
        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        foreach (ImportConsignees::EXPECTED_HEADERS as $col => $label) {
            $sh->setCellValue($col.'1', $label);
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $col => $val) {
                $sh->setCellValue($col.($i + 2), $val);
            }
        }
        $path = sys_get_temp_dir().'/consignee_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function seedRefs(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);
        Country::create(['name' => '토고', 'code' => 'TGO']);
    }

    public function test_new_buyer_gets_detail_columns(): void
    {
        $this->seedRefs();
        $path = $this->sheet([[
            'A' => 'Auto MVE', 'B' => 'GYSII AUTO', 'C' => '토고', 'F' => 'passport', 'G' => 'AB1234567',
            'J' => 'TESTMAN', 'K' => '12 Rue du Commerce',
            'M' => '토고', 'N' => 'Kwame A.', 'O' => '+228 91 00 0000',
            'P' => 'buyer@example.com', 'Q' => 'CD7654321', 'R' => '5 Blvd du 13 Janvier',
        ]]);

        $this->artisan('consignees:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $buyer = Buyer::where('name', 'Auto MVE')->firstOrFail();
        $this->assertSame('Kwame A.', $buyer->contact_name);
        $this->assertSame('+228 91 00 0000', $buyer->contact_phone);
        $this->assertSame('buyer@example.com', $buyer->contact_email, 'Invoice Email 은 바이어 것만 쓴다');
        $this->assertSame('CD7654321', $buyer->passport_id);
        $this->assertSame('5 Blvd du 13 Janvier', $buyer->address);
        $this->assertSame('TGO', $buyer->country?->code);

        // 컨사이니는 별개로 자기 값을 갖는다(바이어 값이 새면 안 됨).
        $c = Consignee::where('name', 'GYSII AUTO')->firstOrFail();
        $this->assertSame('12 Rue du Commerce', $c->address);
        $this->assertSame('AB1234567', $c->id_value);
    }

    /** 🔒 기존 바이어는 **빈 칸만** 채운다 — 운영 값을 양식이 조용히 덮으면 안 된다. */
    public function test_existing_buyer_only_fills_blanks(): void
    {
        $this->seedRefs();
        Buyer::create([
            'name' => 'Auto MVE', 'is_active' => true,
            'contact_email' => 'keep@existing.com',   // 이미 있는 값
            'address' => null,                        // 빈 값
        ]);

        $path = $this->sheet([[
            'A' => 'Auto MVE', 'B' => 'GYSII AUTO', 'J' => 'TESTMAN',
            'P' => 'new@sheet.com', 'R' => '5 Blvd du 13 Janvier',
        ]]);
        $this->artisan('consignees:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $buyer = Buyer::where('name', 'Auto MVE')->firstOrFail();
        $this->assertSame('keep@existing.com', $buyer->contact_email, '기존 값이 덮였다');
        $this->assertSame('5 Blvd du 13 Janvier', $buyer->address, '빈 칸은 채워져야 한다');
    }

    /** 같은 바이어가 여러 줄이면 첫 등장 값을 쓰고, 뒤 행이 달라도 차단하지 않는다. */
    public function test_multiple_rows_same_buyer_uses_first_value(): void
    {
        $this->seedRefs();
        $path = $this->sheet([
            ['A' => 'Auto MVE', 'B' => 'CONSIGNEE 1', 'J' => 'TESTMAN', 'R' => '첫 주소'],
            ['A' => 'Auto MVE', 'B' => 'CONSIGNEE 2', 'J' => 'TESTMAN', 'R' => '다른 주소'],
        ]);

        $this->artisan('consignees:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $this->assertSame('첫 주소', Buyer::where('name', 'Auto MVE')->value('address'));
        $this->assertSame(1, Buyer::where('name', 'Auto MVE')->count(), '바이어가 중복 생성됐다');
        $this->assertSame(2, Consignee::count(), '컨사이니는 행마다 생성된다');
    }

    /** 바이어 열을 안 채워도 종전처럼 동작해야 한다(구 양식 하위호환). */
    public function test_buyer_columns_are_optional(): void
    {
        $this->seedRefs();
        $path = $this->sheet([['A' => 'Auto MVE', 'B' => 'GYSII AUTO', 'J' => 'TESTMAN']]);

        $this->artisan('consignees:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $buyer = Buyer::where('name', 'Auto MVE')->firstOrFail();
        $this->assertNull($buyer->address);
        $this->assertNotNull($buyer->salesman_id);
    }
}
