<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Models\VehicleShipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * EMS·DHL 발송 + 컨테이너 접두어 일괄 교체 시연용 데이터 (jin 2026-09-01).
 *
 * 로컬 DB 에는 컨테이너 번호가 **0건**이라 접두어 드롭다운이 비어 있고, 발송 필터도 볼 게 없다.
 * 이 시더는 «한 배가 통째로 다음 배로 밀리는» 실제 모양을 5대로 줄여 재현한다.
 *
 * ── 무엇을 확인할 수 있나 ─────────────────────────────────────
 *   ① 접두어 일괄 교체 — 차량관리에서 선박명 `GMT DEMO` 로 검색 → [선적일·ETA 일괄]
 *      → 컨테이너 접두어 `6.08_G (3대)` → `6.09_A` + 선적일 변경 → [적용]
 *      ⇒ 6.08_G 3대만 바뀌고 6.08_H·ISO 번호는 그대로여야 한다.
 *   ② 발송 필터·정렬 — 목록 상단 📮 EMS / DHL / 미발송 pill, 발송월 `202608`,
 *      표시컬럼에서 등기번호·운송장번호·발송비·발송일 켜기.
 *   ③ N/1 분배 — 9001·9002 가 **같은 등기번호 하나**를 29,560원에 나눠 달고 있다(각 14,780).
 *   ④ 회사 부담 — 9004 는 번호는 있고 금액이 0 이라 정산에서 안 빠진다.
 *   ⑤ 정산 차감 — 9001 에 프리랜서 정산이 붙어 있어 실지급액에서 발송비가 빠지는 게 보인다.
 *
 * 마커: memo='[SHIPDEMO]'.
 *   생성:  php artisan db:seed --class=ShipmentDemoSeeder
 *   정리:  php artisan tinker --execute="Database\Seeders\ShipmentDemoSeeder::clear();"
 */
class ShipmentDemoSeeder extends Seeder
{
    public const MARKER = '[SHIPDEMO]';

    /** 실데이터와 안 겹치는 번호대 (90저900X). */
    private const ROWS = [
        // 차량번호        컨테이너 번호                 발송(구분/번호/그차몫/접수일)
        ['90저9001', '6.08_G RORO 12-33_5', ['ems', 'ED105401414KR', 14780, '2026-08-12']],
        ['90저9002', '6.08_G RORO 12-34_1', ['ems', 'ED105401414KR', 14780, '2026-08-12']],
        ['90저9003', '6.08_G RORO 12-35_2', ['dhl', '4508354922', 74028, '2026-08-20']],
        ['90저9004', '6.08_H RORO 1-1', ['ems', 'ED999999999KR', 0, '2026-08-05']],
        ['90저9005', 'EISU8533921', null],
    ];

    public static function clear(): void
    {
        $ids = Vehicle::withTrashed()->where('memo', 'like', self::MARKER.'%')->pluck('id');
        if ($ids->isNotEmpty()) {
            VehicleShipment::whereIn('vehicle_id', $ids)->delete();
            Settlement::withoutEvents(fn () => Settlement::whereIn('vehicle_id', $ids)->forceDelete());
            Vehicle::whereIn('id', $ids)->forceDelete();
        }
        Buyer::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
        Salesman::withTrashed()->where('name', 'like', '%'.self::MARKER.'%')->forceDelete();
    }

    public function run(): void
    {
        self::clear();

        $salesman = Salesman::create([
            'name' => self::MARKER.'발송영업', 'type' => 'freelance', 'is_active' => true,
        ]);
        $buyer = Buyer::create(['name' => self::MARKER.'DEMO BUYER', 'is_active' => true]);

        DB::transaction(function () use ($salesman, $buyer) {
            foreach (self::ROWS as [$plate, $containerNo, $shipment]) {
                $vehicle = Vehicle::create([
                    'vehicle_number' => $plate,
                    'sales_channel' => 'export',
                    'currency' => 'KRW',
                    'exchange_rate' => 1,
                    'salesman_id' => $salesman->id,
                    'buyer_id' => $buyer->id,
                    'purchase_price' => 8_000_000,
                    'purchase_date' => '2026-06-01',
                    'sale_price' => 12_000_000,
                    'sale_date' => '2026-07-01',
                    // 접두어 교체 대상을 한 번에 걸러낼 검색어 — 선박명으로 5대가 같이 잡힌다.
                    'vessel_name' => 'GMT DEMO',
                    'container_number' => $containerNo,
                    'shipping_method' => 'RORO',
                    'shipping_date' => '2026-08-25',
                    'eta_date' => '2026-09-20',
                    'dhl_request' => false,
                    'memo' => self::MARKER,
                ]);

                if ($shipment !== null) {
                    [$carrier, $trackingNo, $fee, $sentDate] = $shipment;
                    $vehicle->shipments()->create([
                        'carrier' => $carrier,
                        'tracking_no' => $trackingNo,
                        'fee' => $fee,
                        'sent_date' => $sentDate,
                        'note' => self::MARKER,
                    ]);
                }
            }

            // 첫 차량에만 정산 — 발송비가 실지급액에서 빠지는 걸 정산 화면에서 확인하려고.
            //   pending 이라 회계 락은 안 걸린다(마감이면 발송 기록 자체가 막힌다).
            $first = Vehicle::where('vehicle_number', self::ROWS[0][0])->firstOrFail();
            Settlement::withoutEvents(fn () => Settlement::create([
                'vehicle_id' => $first->id,
                'salesman_id' => $salesman->id,
                'settlement_type' => 'ratio',
                'settlement_ratio' => 50,
                'settlement_status' => 'pending',
            ]));
        });

        $this->command?->info('ShipmentDemoSeeder: 5대 생성 (6.08_G ×3 · 6.08_H ×1 · ISO ×1).');
        $this->command?->info('  차량관리에서 선박명 「GMT DEMO」 검색 → [선적일·ETA 일괄] → 접두어 6.08_G → 6.09_A 로 확인.');
        $this->command?->info('  ⇒ 6.08_G 3대만 바뀌고 6.08_H·EISU8533921 은 그대로여야 정상입니다.');
    }
}
