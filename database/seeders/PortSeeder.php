<?php

namespace Database\Seeders;

use App\Models\Port;
use Illuminate\Database\Seeder;

/**
 * 2026-05-21 — 항구 마스터 시드 (xlsm RO_CIPL/con_CIPL 드롭다운 옵션 이식).
 *
 * 원본 dedupe 결과:
 *   - loading 4 (Port of Loading — 출발항)
 *   - unloading 17 (반입지 — 한국 부두, 중복 4건 + 빈 1건 제거)
 *   - discharge 13 (Discharge Port — 목적항)
 *
 * code 는 괄호 안 부두 번호. 없는 항구는 NULL.
 * updateOrCreate(name + type) — 운영 중 시드 재실행해도 사용자가 추가한 항구 보존.
 */
class PortSeeder extends Seeder
{
    public function run(): void
    {
        $ports = [
            // loading 4
            ['type' => 'loading', 'name' => 'INCHEON, KOREA', 'code' => null],
            ['type' => 'loading', 'name' => 'MASAN, KOREA', 'code' => null],
            ['type' => 'loading', 'name' => 'PYEONGTAEK, KOREA', 'code' => null],
            ['type' => 'loading', 'name' => 'BUSAN, KOREA', 'code' => null],

            // unloading 17 (반입지 — 한국 부두)
            ['type' => 'unloading', 'name' => 'ICT', 'code' => '020-77-002'],
            ['type' => 'unloading', 'name' => 'SNCT', 'code' => '020-12-014'],
            ['type' => 'unloading', 'name' => 'HJIT', 'code' => '020-12-016'],
            ['type' => 'unloading', 'name' => 'PCTC', 'code' => '016-12-033'],
            ['type' => 'unloading', 'name' => 'PNCT', 'code' => '016-12-002'],
            ['type' => 'unloading', 'name' => 'PNIT', 'code' => '030-77-013'],
            ['type' => 'unloading', 'name' => 'HPNT', 'code' => '030-77-011'],
            ['type' => 'unloading', 'name' => 'IPFC', 'code' => '020-12-018'],
            ['type' => 'unloading', 'name' => '인천항 3부두', 'code' => null],
            ['type' => 'unloading', 'name' => '마산 제5부두', 'code' => '05002102'],
            ['type' => 'unloading', 'name' => '인천내항 4부두', 'code' => '0209999'],
            ['type' => 'unloading', 'name' => '세방감천LME', 'code' => '030-77078'],
            ['type' => 'unloading', 'name' => '평택항', 'code' => '01699999'],
            ['type' => 'unloading', 'name' => '평택국제자동차부두 PIRT', 'code' => '1610038'],
            ['type' => 'unloading', 'name' => '평택국제터미널 HPIT', 'code' => '01606020'],
            ['type' => 'unloading', 'name' => '동해항', 'code' => '1099999'],
            ['type' => 'unloading', 'name' => '감천항 6부두', 'code' => '030-78003'],

            // discharge 13 (Discharge Port — 목적항)
            ['type' => 'discharge', 'name' => 'AZERBAIJAN', 'code' => null],
            ['type' => 'discharge', 'name' => 'DURRESS, ALBANIA', 'code' => null],
            ['type' => 'discharge', 'name' => 'KYRGYZSTAN', 'code' => null],
            ['type' => 'discharge', 'name' => 'VLADIVOSTOK, RUSSIA', 'code' => null],
            ['type' => 'discharge', 'name' => 'NAKHODKA, RUSSIA', 'code' => null],
            ['type' => 'discharge', 'name' => 'KAZAKHSTAN', 'code' => null],
            ['type' => 'discharge', 'name' => 'BURGAS, BULGARIA', 'code' => null],
            ['type' => 'discharge', 'name' => 'MOLDOVA', 'code' => null],
            ['type' => 'discharge', 'name' => 'KLAIPEDA', 'code' => null],
            ['type' => 'discharge', 'name' => 'NORRKOPING', 'code' => null],
            ['type' => 'discharge', 'name' => 'U.A.E (DUBAI)', 'code' => null],
            ['type' => 'discharge', 'name' => 'CHILE', 'code' => null],
            ['type' => 'discharge', 'name' => 'GOTHENBURG, SWEDEN', 'code' => null],
        ];

        foreach ($ports as $p) {
            Port::updateOrCreate(
                ['name' => $p['name'], 'type' => $p['type']],
                array_merge($p, ['is_active' => true])
            );
        }
    }
}
