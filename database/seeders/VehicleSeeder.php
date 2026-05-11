<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * 50대 차량 더미 데이터 — 실제 업무 흐름 재현.
 *
 * 단계별 분배:
 *   매입중(5) 매입완료(5) 말소완료(4)
 *   판매중(8) 판매완료(5) 수출통관중(5) 수출통관완료(5)
 *   선적중(5) 선적완료(4) 거래완료(4)  = 50대
 *
 * 미수금 입금 흐름 10건:
 *   판매중 6대 — 계약금 + 잔금 1~2회 입금, 잔액 남음
 *   판매완료/거래완료 4대 — 계약금 + 잔금 여러 회 → 최종 0원
 */
class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $this->addCarpulBuyer();
        $this->seedVehicles();
    }

    private function addCarpulBuyer(): void
    {
        $korea = Country::where('code', 'KOR')->first();
        if (! $korea) {
            return;
        }

        $buyer = Buyer::updateOrCreate(
            ['name' => '카풀거래소'],
            [
                'name' => '카풀거래소',
                'country_id' => $korea->id,
                'contact_name' => '박카풀',
                'contact_email' => 'park@carpul.co.kr',
                'contact_phone' => '010-7777-8888',
                'address' => '서울시 서초구 강남대로 200',
                'is_active' => true,
            ]
        );

        Consignee::updateOrCreate(
            ['name' => '카풀거래소 CONSIGNEE'],
            [
                'name' => '카풀거래소 CONSIGNEE',
                'buyer_id' => $buyer->id,
                'country_id' => $korea->id,
                'contact_name' => '박카풀',
                'contact_email' => 'park@carpul.co.kr',
                'contact_phone' => '010-7777-8888',
                'address' => '서울시 서초구 강남대로 200',
                'is_active' => true,
            ]
        );
    }

    private function seedVehicles(): void
    {
        // ── 참조 데이터 로드 ────────────────────────────────────────
        $buyers = Buyer::all()->keyBy('name');
        $consignees = Consignee::all();
        $fwds = ForwardingCompany::all();
        $salesmen = Salesman::all();

        $tokyo = $buyers['TOKYO AUTO TRADING'] ?? null;
        $osaka = $buyers['OSAKA MOTORS CO.'] ?? null;
        $mongol = $buyers['MONGOLIA BEST CAR'] ?? null;
        $mongol2 = $buyers['UB AUTO GROUP'] ?? null;
        $myanmar = $buyers['YANGON CAR IMPORT'] ?? null;
        $heyman = $buyers['헤이맨모터스'] ?? null;
        $carpul = $buyers['카풀거래소'] ?? null;

        $cons = fn ($buyer) => $buyer ? $consignees->where('buyer_id', $buyer->id)->first() : null;

        $fwd1 = $fwds->get(0);
        $fwd2 = $fwds->get(1);
        $fwd3 = $fwds->get(2);
        $sm1 = $salesmen->get(0); // 김영업
        $sm2 = $salesmen->get(1); // 최매입
        $sm3 = $salesmen->get(2); // 정수출

        // ── 공통 헬퍼 ────────────────────────────────────────────────
        $exportBase = fn (array $extra) => array_merge([
            'sales_channel' => 'export',
            'currency' => 'USD',
        ], $extra);

        // ══════════════════════════════════════════════════════════════
        // Group 1: 매입중 (5대) — 매입만 됐고 매입가 미입력 또는 미지급
        // ══════════════════════════════════════════════════════════════
        $this->upsert([
            'vehicle_number' => '10가1001', 'brand' => '현대', 'model_type' => '그랜저',
            'year' => 2019, 'cc' => 2999, 'weight_kg' => 1700, 'mileage' => 72000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2026-05-01', 'salesman_id' => $sm1?->id,
            'purchase_from' => '현대직영 강남점',
        ]);
        $this->upsert([
            'vehicle_number' => '10나1002', 'brand' => '기아', 'model_type' => '모하비',
            'year' => 2018, 'cc' => 2993, 'weight_kg' => 2100, 'mileage' => 98000, 'color' => '검정',
            'sales_channel' => 'export', 'purchase_date' => '2026-05-03', 'salesman_id' => $sm2?->id,
            'purchase_from' => '경매 낙찰',
        ]);
        $this->upsert([
            'vehicle_number' => '10다1003', 'brand' => '쌍용', 'model_type' => '렉스턴',
            'year' => 2020, 'cc' => 2157, 'weight_kg' => 1990, 'mileage' => 54000, 'color' => '은색',
            'sales_channel' => 'export', 'purchase_date' => '2026-05-05', 'salesman_id' => $sm1?->id,
            'purchase_from' => '개인 매입',
        ]);
        $this->upsert([
            'vehicle_number' => '10라1004', 'brand' => '현대', 'model_type' => '싼타페',
            'year' => 2021, 'cc' => 2199, 'weight_kg' => 1910, 'mileage' => 31000, 'color' => '파랑',
            'sales_channel' => 'heyman', 'purchase_date' => '2026-05-06', 'salesman_id' => $sm3?->id,
            'purchase_from' => '법인 매입',
        ]);
        $this->upsert([
            'vehicle_number' => '10마1005', 'brand' => '기아', 'model_type' => 'K7',
            'year' => 2019, 'cc' => 2497, 'weight_kg' => 1620, 'mileage' => 67000, 'color' => '진주',
            'sales_channel' => 'export', 'purchase_date' => '2026-05-07', 'salesman_id' => $sm2?->id,
            'purchase_from' => '경매 낙찰',
        ]);

        // ══════════════════════════════════════════════════════════════
        // Group 2: 매입완료 (5대) — 매입가 전액 지급
        // ══════════════════════════════════════════════════════════════
        $this->upsert([
            'vehicle_number' => '20가2001', 'brand' => '현대', 'model_type' => '아이오닉5',
            'year' => 2022, 'cc' => 0, 'weight_kg' => 2100, 'mileage' => 28000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2026-04-20', 'salesman_id' => $sm1?->id,
            'purchase_from' => '현대직영 판교점',
            'purchase_price' => 22000000, 'selling_fee' => 600000,
            'down_payment' => 22000000, 'selling_fee_payment' => 600000,
        ]);
        $this->upsert([
            'vehicle_number' => '20나2002', 'brand' => '기아', 'model_type' => '카니발',
            'year' => 2020, 'cc' => 2199, 'weight_kg' => 2095, 'mileage' => 85000, 'color' => '검정',
            'sales_channel' => 'export', 'purchase_date' => '2026-04-18', 'salesman_id' => $sm2?->id,
            'purchase_from' => '딜러 매입',
            'purchase_price' => 16000000, 'selling_fee' => 450000,
            'down_payment' => 16000000, 'selling_fee_payment' => 450000,
        ]);
        $this->upsert([
            'vehicle_number' => '20다2003', 'brand' => '현대', 'model_type' => '투싼',
            'year' => 2021, 'cc' => 1598, 'weight_kg' => 1580, 'mileage' => 43000, 'color' => '회색',
            'sales_channel' => 'export', 'purchase_date' => '2026-04-15', 'salesman_id' => $sm3?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 17500000, 'selling_fee' => 500000,
            'down_payment' => 17500000, 'selling_fee_payment' => 500000,
        ]);
        $this->upsert([
            'vehicle_number' => '20라2004', 'brand' => '르노', 'model_type' => 'QM6',
            'year' => 2020, 'cc' => 1999, 'weight_kg' => 1670, 'mileage' => 55000, 'color' => '흰색',
            'sales_channel' => 'heyman', 'purchase_date' => '2026-04-22', 'salesman_id' => $sm1?->id,
            'purchase_from' => '개인 매입',
            'purchase_price' => 14500000, 'selling_fee' => 400000,
            'down_payment' => 14500000, 'selling_fee_payment' => 400000,
        ]);
        $this->upsert([
            'vehicle_number' => '20마2005', 'brand' => '기아', 'model_type' => '셀토스',
            'year' => 2022, 'cc' => 1598, 'weight_kg' => 1430, 'mileage' => 22000, 'color' => '빨강',
            'sales_channel' => 'carpul', 'purchase_date' => '2026-04-25', 'salesman_id' => $sm2?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 19000000, 'selling_fee' => 500000,
            'down_payment' => 19000000, 'selling_fee_payment' => 500000,
        ]);

        // ══════════════════════════════════════════════════════════════
        // Group 3: 말소완료 (4대)
        // ══════════════════════════════════════════════════════════════
        foreach ([
            ['30가3001', '현대', '팰리세이드', 2021, 2199, 2060, 48000, '검정', 'export', '2026-04-05', $sm1, 21000000, 600000],
            ['30나3002', '기아', '스포티지',   2020, 1591, 1545, 61000, '회색', 'export', '2026-04-02', $sm2, 15000000, 420000],
            ['30다3003', '쌍용', '티볼리',     2021, 1497, 1290, 37000, '흰색', 'export', '2026-04-08', $sm3, 13500000, 380000],
            ['30라3004', '현대', '아반떼',     2021, 1591, 1270, 29000, '파랑', 'carpul', '2026-04-10', $sm1, 16000000, 450000],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $ch, $pdate, $sm, $pp, $sf]) {
            $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => $ch, 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => $pp, 'selling_fee' => $sf,
                'down_payment' => $pp, 'selling_fee_payment' => $sf,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'cost_deregistration' => 50000,
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // Group 4: 판매중 (8대)
        //   이 중 6대(V15~V20)는 미수금 입금 흐름 대상
        // ══════════════════════════════════════════════════════════════

        // V15: 계약금만 입금 (미수금 8,500 USD)
        $v15 = $this->upsert([
            'vehicle_number' => '40가4001', 'brand' => '현대', 'model_type' => '그랜저',
            'year' => 2020, 'cc' => 2497, 'weight_kg' => 1700, 'mileage' => 58000, 'color' => '검정',
            'sales_channel' => 'export', 'purchase_date' => '2026-03-20', 'salesman_id' => $sm1?->id,
            'purchase_from' => '현대직영',
            'purchase_price' => 18000000, 'selling_fee' => 500000, 'cost_towing' => 150000,
            'down_payment' => 18000000, 'selling_fee_payment' => 500000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40가4001.pdf',
            'sale_date' => '2026-04-01',
            'currency' => 'USD', 'exchange_rate' => 1350.00,
            'buyer_id' => $tokyo?->id, 'consignee_id' => $cons($tokyo)?->id,
            'sale_price' => 13500.00, 'transport_fee' => 700.00,
            'deposit_down_payment' => 5000.00,
        ]);
        // 잔금 없음 → 미수금 = 13500 + 700 - 5000 = 9200 USD

        // V16: 계약금 + 잔금1 입금 (미수금 5,700 USD)
        $v16 = $this->upsert([
            'vehicle_number' => '40나4002', 'brand' => '기아', 'model_type' => '쏘렌토',
            'year' => 2021, 'cc' => 1999, 'weight_kg' => 1845, 'mileage' => 42000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2026-03-18', 'salesman_id' => $sm2?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 17000000, 'selling_fee' => 480000, 'cost_towing' => 130000,
            'down_payment' => 17000000, 'selling_fee_payment' => 480000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40나4002.pdf',
            'sale_date' => '2026-03-28',
            'currency' => 'USD', 'exchange_rate' => 1345.00,
            'buyer_id' => $osaka?->id, 'consignee_id' => $cons($osaka)?->id,
            'sale_price' => 12800.00, 'transport_fee' => 650.00,
            'deposit_down_payment' => 4000.00,
        ]);
        FinalPayment::updateOrCreate(
            ['vehicle_id' => $v16->id, 'payment_date' => '2026-04-15'],
            ['vehicle_id' => $v16->id, 'amount' => 3100.00, 'payment_date' => '2026-04-15', 'note' => '1차 잔금']
        );
        // 미수금 = 12800 + 650 - 4000 - 3100 = 6350 USD

        // V17: 계약금 + 잔금1 + 잔금2 입금 (미수금 2,850 USD)
        $v17 = $this->upsert([
            'vehicle_number' => '40다4003', 'brand' => '현대', 'model_type' => '팰리세이드',
            'year' => 2022, 'cc' => 2199, 'weight_kg' => 2060, 'mileage' => 25000, 'color' => '은색',
            'sales_channel' => 'export', 'purchase_date' => '2026-03-10', 'salesman_id' => $sm3?->id,
            'purchase_from' => '법인 매입',
            'purchase_price' => 24000000, 'selling_fee' => 700000, 'cost_towing' => 180000, 'cost_carry' => 120000,
            'down_payment' => 24000000, 'selling_fee_payment' => 700000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40다4003.pdf',
            'sale_date' => '2026-03-20',
            'currency' => 'USD', 'exchange_rate' => 1360.00,
            'buyer_id' => $mongol?->id, 'consignee_id' => $cons($mongol)?->id,
            'sale_price' => 16500.00, 'transport_fee' => 850.00,
            'deposit_down_payment' => 5000.00,
        ]);
        FinalPayment::updateOrCreate(
            ['vehicle_id' => $v17->id, 'payment_date' => '2026-04-01'],
            ['vehicle_id' => $v17->id, 'amount' => 5000.00, 'payment_date' => '2026-04-01', 'note' => '1차 잔금']
        );
        FinalPayment::updateOrCreate(
            ['vehicle_id' => $v17->id, 'payment_date' => '2026-04-20'],
            ['vehicle_id' => $v17->id, 'amount' => 4000.00, 'payment_date' => '2026-04-20', 'note' => '2차 잔금']
        );
        // 미수금 = 16500 + 850 - 5000 - 5000 - 4000 = 3350 USD

        // V18: 계약금만 입금 (미수금 큼) - 헤이맨 채널 KRW
        $v18 = $this->upsert([
            'vehicle_number' => '40라4004', 'brand' => '기아', 'model_type' => 'K5',
            'year' => 2021, 'cc' => 1591, 'weight_kg' => 1380, 'mileage' => 38000, 'color' => '흰색',
            'sales_channel' => 'heyman', 'purchase_date' => '2026-03-25', 'salesman_id' => $sm1?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 16000000, 'selling_fee' => 400000,
            'down_payment' => 16000000, 'selling_fee_payment' => 400000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40라4004.pdf',
            'sale_date' => '2026-04-05',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'buyer_id' => $heyman?->id,
            'sale_price' => 19800000,
            'deposit_down_payment' => 5000000,
        ]);
        FinalPayment::updateOrCreate(
            ['vehicle_id' => $v18->id, 'payment_date' => '2026-04-25'],
            ['vehicle_id' => $v18->id, 'amount' => 5000000, 'payment_date' => '2026-04-25', 'note' => '1차 잔금']
        );
        // 미수금 = 19800000 - 5000000 - 5000000 = 9800000 KRW

        // V19: 계약금 + 잔금1 입금 (미수금 남음) - 카풀 채널 KRW
        $v19 = $this->upsert([
            'vehicle_number' => '40마4005', 'brand' => '현대', 'model_type' => '아반떼',
            'year' => 2022, 'cc' => 1591, 'weight_kg' => 1270, 'mileage' => 18000, 'color' => '파랑',
            'sales_channel' => 'carpul', 'purchase_date' => '2026-03-28', 'salesman_id' => $sm2?->id,
            'purchase_from' => '개인 매입',
            'purchase_price' => 18500000, 'selling_fee' => 500000,
            'down_payment' => 18500000, 'selling_fee_payment' => 500000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40마4005.pdf',
            'sale_date' => '2026-04-08',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'buyer_id' => $carpul?->id,
            'sale_price' => 22500000, 'agency_fee' => 500000,
            'deposit_down_payment' => 7000000,
        ]);
        FinalPayment::updateOrCreate(
            ['vehicle_id' => $v19->id, 'payment_date' => '2026-04-28'],
            ['vehicle_id' => $v19->id, 'amount' => 6000000, 'payment_date' => '2026-04-28', 'note' => '1차 잔금']
        );
        // 미수금 = 22500000 + 500000 - 7000000 - 6000000 = 10000000 KRW

        // V20: 계약금만 입금 (미수금 남음)
        $v20 = $this->upsert([
            'vehicle_number' => '40바4006', 'brand' => '쌍용', 'model_type' => '코란도',
            'year' => 2021, 'cc' => 1496, 'weight_kg' => 1560, 'mileage' => 44000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2026-03-22', 'salesman_id' => $sm3?->id,
            'purchase_from' => '딜러 매입',
            'purchase_price' => 13000000, 'selling_fee' => 350000, 'cost_towing' => 100000,
            'down_payment' => 13000000, 'selling_fee_payment' => 350000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40바4006.pdf',
            'sale_date' => '2026-04-03',
            'currency' => 'USD', 'exchange_rate' => 1340.00,
            'buyer_id' => $myanmar?->id, 'consignee_id' => $cons($myanmar)?->id,
            'sale_price' => 9500.00, 'transport_fee' => 600.00,
            'deposit_down_payment' => 3500.00,
        ]);
        // 미수금 = 9500 + 600 - 3500 = 6600 USD

        // V21: 일반 판매중 (수출, 계약금 입금)
        $this->upsert([
            'vehicle_number' => '40사4007', 'brand' => '현대', 'model_type' => '싼타페',
            'year' => 2020, 'cc' => 2199, 'weight_kg' => 1910, 'mileage' => 72000, 'color' => '회색',
            'sales_channel' => 'export', 'purchase_date' => '2026-03-15', 'salesman_id' => $sm1?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 16000000, 'selling_fee' => 450000,
            'down_payment' => 16000000, 'selling_fee_payment' => 450000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40사4007.pdf',
            'sale_date' => '2026-03-28',
            'currency' => 'USD', 'exchange_rate' => 1348.00,
            'buyer_id' => $mongol2?->id, 'consignee_id' => $cons($mongol2)?->id,
            'sale_price' => 12000.00, 'transport_fee' => 600.00,
            'deposit_down_payment' => 5000.00,
        ]);

        // V22: 일반 판매중 (헤이맨, 계약금 입금)
        $this->upsert([
            'vehicle_number' => '40아4008', 'brand' => '기아', 'model_type' => 'K7',
            'year' => 2020, 'cc' => 2497, 'weight_kg' => 1620, 'mileage' => 62000, 'color' => '검정',
            'sales_channel' => 'heyman', 'purchase_date' => '2026-03-12', 'salesman_id' => $sm2?->id,
            'purchase_from' => '법인 매입',
            'purchase_price' => 15000000, 'selling_fee' => 420000,
            'down_payment' => 15000000, 'selling_fee_payment' => 420000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_40아4008.pdf',
            'sale_date' => '2026-03-25',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'buyer_id' => $heyman?->id,
            'sale_price' => 18500000,
            'deposit_down_payment' => 5000000,
        ]);

        // ══════════════════════════════════════════════════════════════
        // Group 5: 판매완료 (5대)
        //   이 중 2대(V23, V24)는 미수금 0원으로 완료된 흐름
        // ══════════════════════════════════════════════════════════════

        // V23: 계약금 + 잔금 3회 → 미수금 0 완납 (수출 USD)
        $v23 = $this->upsert([
            'vehicle_number' => '50가5001', 'brand' => '현대', 'model_type' => '넥쏘',
            'year' => 2021, 'cc' => 0, 'weight_kg' => 1820, 'mileage' => 35000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2026-02-10', 'salesman_id' => $sm1?->id,
            'purchase_from' => '현대직영',
            'purchase_price' => 23000000, 'selling_fee' => 650000, 'cost_towing' => 200000, 'cost_carry' => 100000,
            'down_payment' => 23000000, 'selling_fee_payment' => 650000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_50가5001.pdf',
            'sale_date' => '2026-02-25',
            'currency' => 'USD', 'exchange_rate' => 1355.00,
            'buyer_id' => $tokyo?->id, 'consignee_id' => $cons($tokyo)?->id,
            'sale_price' => 15000.00, 'transport_fee' => 800.00,
            'deposit_down_payment' => 5000.00, // 계약금
        ]);
        // 총 판매액 = 15000 + 800 = 15800 USD
        // 계약금 5000 → 잔금1 4000 → 잔금2 3800 → 잔금3 3000 = 합계 15800 → 미수금 0
        FinalPayment::updateOrCreate(['vehicle_id' => $v23->id, 'payment_date' => '2026-03-10'],
            ['vehicle_id' => $v23->id, 'amount' => 4000.00, 'payment_date' => '2026-03-10', 'note' => '1차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v23->id, 'payment_date' => '2026-03-25'],
            ['vehicle_id' => $v23->id, 'amount' => 3800.00, 'payment_date' => '2026-03-25', 'note' => '2차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v23->id, 'payment_date' => '2026-04-10'],
            ['vehicle_id' => $v23->id, 'amount' => 3000.00, 'payment_date' => '2026-04-10', 'note' => '3차 잔금 (완납)']);

        // V24: 계약금 + 잔금 2회 → 미수금 0 완납 (헤이맨 KRW)
        $v24 = $this->upsert([
            'vehicle_number' => '50나5002', 'brand' => '기아', 'model_type' => '스포티지',
            'year' => 2022, 'cc' => 1598, 'weight_kg' => 1545, 'mileage' => 22000, 'color' => '빨강',
            'sales_channel' => 'heyman', 'purchase_date' => '2026-02-05', 'salesman_id' => $sm2?->id,
            'purchase_from' => '기아직영',
            'purchase_price' => 21000000, 'selling_fee' => 580000,
            'down_payment' => 21000000, 'selling_fee_payment' => 580000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_50나5002.pdf',
            'sale_date' => '2026-02-20',
            'currency' => 'KRW', 'exchange_rate' => 1,
            'buyer_id' => $heyman?->id,
            'sale_price' => 25000000,
            'deposit_down_payment' => 8000000,
        ]);
        // 총 판매액 = 25000000. 계약금 8000000 → 잔금1 9000000 → 잔금2 8000000 = 합계 25000000 → 미수금 0
        FinalPayment::updateOrCreate(['vehicle_id' => $v24->id, 'payment_date' => '2026-03-05'],
            ['vehicle_id' => $v24->id, 'amount' => 9000000, 'payment_date' => '2026-03-05', 'note' => '1차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v24->id, 'payment_date' => '2026-03-20'],
            ['vehicle_id' => $v24->id, 'amount' => 8000000, 'payment_date' => '2026-03-20', 'note' => '2차 잔금 (완납)']);

        // V25~V27: 일반 판매완료 (전액 입금)
        foreach ([
            ['50다5003', '현대', '싼타페', 2019, 2199, 1910, 88000, '흰색', 'export', '2026-02-01', $sm3, $mongol, $cons($mongol), 1340.00, 11500.00, 550.00, 5000.00, 7050.00],
            ['50라5004', '기아', '카니발', 2020, 2199, 2095, 76000, '검정', 'export', '2026-01-25', $sm1, $myanmar, $cons($myanmar), 1335.00, 10200.00, 900.00, 4500.00, 6600.00],
            ['50마5005', '현대', '투싼',   2022, 1598, 1580, 19000, '파랑', 'export', '2026-02-08', $sm2, $osaka, $cons($osaka), 1358.00, 13500.00, 700.00, 5000.00, 9200.00],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $ch, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $dep, $fp1]) {
            $v = $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => $ch, 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => (int) ($sp * $rate * 0.85), 'selling_fee' => 400000,
                'down_payment' => (int) ($sp * $rate * 0.85), 'selling_fee_payment' => 400000,
                'cost_towing' => 130000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $dep,
            ]);
            FinalPayment::updateOrCreate(
                ['vehicle_id' => $v->id, 'payment_date' => date('Y-m-d', strtotime($pdate.' +30 days'))],
                ['vehicle_id' => $v->id, 'amount' => $fp1, 'payment_date' => date('Y-m-d', strtotime($pdate.' +30 days')), 'note' => '잔금 (완납)']
            );
        }

        // ══════════════════════════════════════════════════════════════
        // Group 6: 수출통관중 (5대)
        // ══════════════════════════════════════════════════════════════
        foreach ([
            ['60가6001', '현대', '그랜저',   2021, 2497, 1700, 49000, '검정', '2026-01-20', $sm1, $tokyo,   $cons($tokyo),   1350.00, 14000.00, 750.00, '2026-03-10', $fwd1],
            ['60나6002', '기아', '쏘렌토',   2022, 1999, 1845, 31000, '흰색', '2026-01-15', $sm2, $mongol,  $cons($mongol),  1345.00, 12500.00, 600.00, '2026-03-05', $fwd2],
            ['60다6003', '현대', '팰리세이드', 2020, 2199, 2060, 65000, '은색', '2026-01-18', $sm3, $osaka,   $cons($osaka),   1360.00, 15500.00, 800.00, '2026-03-15', $fwd3],
            ['60라6004', '기아', '모하비',   2019, 2993, 2100, 91000, '검정', '2026-01-10', $sm1, $mongol2, $cons($mongol2), 1340.00, 11000.00, 550.00, '2026-03-01', $fwd1],
            ['60마6005', '현대', '싼타페',   2021, 2199, 1910, 55000, '흰색', '2026-01-22', $sm2, $myanmar, $cons($myanmar), 1338.00, 10500.00, 900.00, '2026-03-20', $fwd2],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $shdate, $fwd]) {
            $pp = (int) ($sp * $rate * 0.82);
            $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => 'export', 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => $pp, 'selling_fee' => 400000, 'cost_towing' => 130000, 'cost_carry' => 80000,
                'down_payment' => $pp, 'selling_fee_payment' => 400000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $sp, // 전액 입금 완료
                'export_buyer_id' => $buyer?->id, 'export_consignee_id' => $consig?->id,
                'forwarding_company_id' => $fwd?->id,
                'shipping_date' => $shdate,
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // Group 7: 수출통관완료 (5대)
        // ══════════════════════════════════════════════════════════════
        foreach ([
            ['70가7001', '기아',  'K5',      2021, 1591, 1380, 41000, '흰색', '2025-12-10', $sm3, $tokyo,   $cons($tokyo),   1355.00, 10500.00, 550.00, '2026-01-20', $fwd1, 10500.00],
            ['70나7002', '현대', '투싼',     2020, 1598, 1580, 58000, '회색', '2025-12-05', $sm1, $osaka,   $cons($osaka),   1348.00, 11800.00, 620.00, '2026-01-15', $fwd2, 11800.00],
            ['70다7003', '기아', '카니발',   2019, 2199, 2095, 99000, '검정', '2025-12-15', $sm2, $mongol,  $cons($mongol),  1342.00, 9800.00,  800.00, '2026-01-25', $fwd3, 9800.00],
            ['70라7004', '현대', '싼타페',   2020, 2199, 1910, 77000, '은색', '2025-12-08', $sm3, $mongol2, $cons($mongol2), 1350.00, 12200.00, 700.00, '2026-01-18', $fwd1, 12200.00],
            ['70마7005', '쌍용', '렉스턴',   2020, 2157, 1990, 62000, '검정', '2025-12-20', $sm1, $myanmar, $cons($myanmar), 1338.00, 9000.00,  850.00, '2026-02-01', $fwd2, 9000.00],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $shdate, $fwd, $eda]) {
            $pp = (int) ($sp * $rate * 0.83);
            $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => 'export', 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => $pp, 'selling_fee' => 400000, 'cost_towing' => 140000, 'cost_shoring' => 200000,
                'down_payment' => $pp, 'selling_fee_payment' => 400000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $sp,
                'export_buyer_id' => $buyer?->id, 'export_consignee_id' => $consig?->id,
                'forwarding_company_id' => $fwd?->id,
                'export_declaration_amount' => $eda,
                'shipping_date' => $shdate,
                'shipping_method' => 'RORO',
                'port_of_loading' => '부산항',
                'export_declaration_document' => "documents/export_decl_{$no}.pdf",
                'export_declaration_number' => 'EX'.str_replace(['가', '나', '다', '라', '마'], ['A', 'B', 'C', 'D', 'E'], $no).date('Ymd', strtotime($shdate)),
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // Group 8: 선적중 (5대) — bl_loading_location 입력
        // ══════════════════════════════════════════════════════════════
        $loadings = ['부산신항 1부두', '부산신항 3부두', '인천항 7부두', '평택항 2게이트', '광양항 1부두'];
        foreach ([
            ['80가8001', '현대', '그랜저',   2019, 2497, 1700, 82000, '검정', '2025-11-10', $sm2, $tokyo,   $cons($tokyo),   1352.00, 13000.00, 700.00, '2025-12-20', $fwd1, 0],
            ['80나8002', '기아', '쏘렌토',   2020, 1999, 1845, 73000, '흰색', '2025-11-05', $sm3, $osaka,   $cons($osaka),   1346.00, 11500.00, 600.00, '2025-12-15', $fwd2, 1],
            ['80다8003', '현대', '팰리세이드', 2021, 2199, 2060, 55000, '은색', '2025-11-15', $sm1, $mongol,  $cons($mongol),  1358.00, 15000.00, 800.00, '2025-12-25', $fwd3, 2],
            ['80라8004', '기아', 'K7',       2020, 2497, 1620, 61000, '진주', '2025-11-08', $sm2, $mongol2, $cons($mongol2), 1342.00, 10800.00, 550.00, '2025-12-18', $fwd1, 3],
            ['80마8005', '현대', '투싼',     2021, 1598, 1580, 47000, '파랑', '2025-11-20', $sm3, $myanmar, $cons($myanmar), 1340.00, 10000.00, 900.00, '2025-12-28', $fwd2, 4],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $shdate, $fwd, $li]) {
            $pp = (int) ($sp * $rate * 0.82);
            $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => 'export', 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => $pp, 'selling_fee' => 420000, 'cost_towing' => 150000, 'cost_carry' => 90000, 'cost_shoring' => 200000,
                'down_payment' => $pp, 'selling_fee_payment' => 420000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $sp,
                'export_buyer_id' => $buyer?->id, 'export_consignee_id' => $consig?->id,
                'forwarding_company_id' => $fwd?->id,
                'export_declaration_amount' => $sp,
                'shipping_date' => $shdate,
                'shipping_method' => 'CONTAINER',
                'port_of_loading' => '부산항',
                'export_declaration_document' => "documents/export_decl_{$no}.pdf",
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $buyer?->id, 'bl_consignee_id' => $consig?->id,
                'bl_loading_location' => $loadings[$li],
                'vessel_name' => ['EVER GIVEN', 'MSC OSCAR', 'PIONEER', 'STELLAR ACE', 'ORIENT WAY'][$li],
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // Group 9: 선적완료 (4대) — bl_document 있음
        // ══════════════════════════════════════════════════════════════
        foreach ([
            ['90가9001', '기아', '카니발',    2020, 2199, 2095, 95000, '검정', '2025-10-10', $sm1, $tokyo,   $cons($tokyo),   1348.00, 9500.00,  800.00, '2025-11-20', $fwd1, 'BUSJ20251120001', '2025-11-22'],
            ['90나9002', '현대', '싼타페',    2020, 2199, 1910, 80000, '흰색', '2025-10-05', $sm2, $osaka,   $cons($osaka),   1342.00, 11000.00, 700.00, '2025-11-15', $fwd2, 'BUSJ20251115003', '2025-11-17'],
            ['90다9003', '기아', '스포티지',  2021, 1591, 1545, 52000, '회색', '2025-10-15', $sm3, $mongol,  $cons($mongol),  1355.00, 10200.00, 600.00, '2025-11-25', $fwd3, 'PTK20251125002',  '2025-11-27'],
            ['90라9004', '현대', '아이오닉5', 2022, 0,    2100, 28000, '파랑', '2025-10-20', $sm1, $mongol2, $cons($mongol2), 1360.00, 18000.00, 850.00, '2025-12-01', $fwd1, 'ICN20251201001',  '2025-12-03'],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $shdate, $fwd, $blno, $bldate]) {
            $pp = (int) ($sp * $rate * 0.83);
            $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => 'export', 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => $pp, 'selling_fee' => 430000, 'cost_towing' => 160000, 'cost_carry' => 100000, 'cost_shoring' => 220000,
                'down_payment' => $pp, 'selling_fee_payment' => 430000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $sp,
                'export_buyer_id' => $buyer?->id, 'export_consignee_id' => $consig?->id,
                'forwarding_company_id' => $fwd?->id,
                'export_declaration_amount' => $sp,
                'shipping_date' => $shdate,
                'shipping_method' => 'RORO',
                'port_of_loading' => '부산항',
                'export_declaration_document' => "documents/export_decl_{$no}.pdf",
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $buyer?->id, 'bl_consignee_id' => $consig?->id,
                'bl_number' => $blno,
                'bl_loading_location' => '부산신항 2부두',
                'vessel_name' => 'PACIFIC HIGHWAY',
                'bl_document' => "documents/bl_{$no}.pdf",
                'bl_issue_date' => $bldate,
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // Group 10: 거래완료 (4대)
        //   이 중 2대(V49, V50)는 미수금 완납 흐름
        // ══════════════════════════════════════════════════════════════

        // V49: 계약금 + 잔금 4회 → 미수금 0 완납 후 거래완료
        $v49 = $this->upsert([
            'vehicle_number' => 'A0가0001', 'brand' => '현대', 'model_type' => '팰리세이드',
            'year' => 2021, 'cc' => 2199, 'weight_kg' => 2060, 'mileage' => 42000, 'color' => '검정',
            'sales_channel' => 'export', 'purchase_date' => '2025-09-01', 'salesman_id' => $sm1?->id,
            'purchase_from' => '법인 매입',
            'purchase_price' => 20000000, 'selling_fee' => 550000, 'cost_towing' => 180000, 'cost_carry' => 120000, 'cost_shoring' => 250000,
            'down_payment' => 20000000, 'selling_fee_payment' => 550000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_A0가0001.pdf',
            'sale_date' => '2025-09-15',
            'currency' => 'USD', 'exchange_rate' => 1340.00,
            'buyer_id' => $tokyo?->id, 'consignee_id' => $cons($tokyo)?->id,
            'sale_price' => 15500.00, 'transport_fee' => 800.00,
            'deposit_down_payment' => 5000.00,
            'export_buyer_id' => $tokyo?->id, 'export_consignee_id' => $cons($tokyo)?->id,
            'forwarding_company_id' => $fwd1?->id,
            'export_declaration_amount' => 15500.00,
            'shipping_date' => '2025-10-20',
            'shipping_method' => 'RORO', 'port_of_loading' => '부산항',
            'export_declaration_document' => 'documents/export_decl_A0가0001.pdf',
            'is_export_cleared' => true, 'forwarding_email_sent' => true,
            'bl_buyer_id' => $tokyo?->id, 'bl_consignee_id' => $cons($tokyo)?->id,
            'bl_number' => 'BUSJ20251025001',
            'bl_loading_location' => '부산신항 1부두',
            'vessel_name' => 'EVER GIVEN',
            'bl_document' => 'documents/bl_A0가0001.pdf',
            'bl_issue_date' => '2025-10-25',
            'dhl_request' => true,
            'dhl_recipient_name' => 'Tanaka Hiroshi',
            'dhl_recipient_address' => '1-2-3 Shibuya, Tokyo, Japan',
            'dhl_recipient_phone' => '+81-3-1234-5678',
            'dhl_sender_name' => 'SSANCAR LTD.',
            'dhl_sender_address' => '서울시 강남구 삼성로 100',
            'dhl_weight' => 1.5, 'dhl_dimensions' => '30x20x10',
        ]);
        // 총 판매액 = 15500 + 800 = 16300 USD
        // 계약금 5000 → 잔금1 3000(10-01) → 잔금2 3500(10-15) → 잔금3 2800(10-28) → 잔금4 2000(11-05) = 16300 → 미수금 0
        FinalPayment::updateOrCreate(['vehicle_id' => $v49->id, 'payment_date' => '2025-10-01'],
            ['vehicle_id' => $v49->id, 'amount' => 3000.00, 'payment_date' => '2025-10-01', 'note' => '1차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v49->id, 'payment_date' => '2025-10-15'],
            ['vehicle_id' => $v49->id, 'amount' => 3500.00, 'payment_date' => '2025-10-15', 'note' => '2차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v49->id, 'payment_date' => '2025-10-28'],
            ['vehicle_id' => $v49->id, 'amount' => 2800.00, 'payment_date' => '2025-10-28', 'note' => '3차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v49->id, 'payment_date' => '2025-11-05'],
            ['vehicle_id' => $v49->id, 'amount' => 2000.00, 'payment_date' => '2025-11-05', 'note' => '4차 잔금 (완납)']);

        // V50: 계약금 + 잔금 3회 → 미수금 0 완납 후 거래완료 (몽골 USD)
        $v50 = $this->upsert([
            'vehicle_number' => 'A0나0002', 'brand' => '기아', 'model_type' => '쏘렌토',
            'year' => 2021, 'cc' => 1999, 'weight_kg' => 1845, 'mileage' => 38000, 'color' => '흰색',
            'sales_channel' => 'export', 'purchase_date' => '2025-09-10', 'salesman_id' => $sm2?->id,
            'purchase_from' => '경매 낙찰',
            'purchase_price' => 17000000, 'selling_fee' => 480000, 'cost_towing' => 140000, 'cost_carry' => 100000,
            'down_payment' => 17000000, 'selling_fee_payment' => 480000,
            'is_deregistered' => true, 'deregistration_document' => 'documents/dereg_A0나0002.pdf',
            'sale_date' => '2025-09-25',
            'currency' => 'USD', 'exchange_rate' => 1336.00,
            'buyer_id' => $mongol?->id, 'consignee_id' => $cons($mongol)?->id,
            'sale_price' => 12800.00, 'transport_fee' => 600.00,
            'deposit_down_payment' => 4000.00,
            'export_buyer_id' => $mongol?->id, 'export_consignee_id' => $cons($mongol)?->id,
            'forwarding_company_id' => $fwd2?->id,
            'export_declaration_amount' => 12800.00,
            'shipping_date' => '2025-10-28',
            'shipping_method' => 'RORO', 'port_of_loading' => '인천항',
            'export_declaration_document' => 'documents/export_decl_A0나0002.pdf',
            'is_export_cleared' => true, 'forwarding_email_sent' => true,
            'bl_buyer_id' => $mongol?->id, 'bl_consignee_id' => $cons($mongol)?->id,
            'bl_number' => 'ICN20251101002',
            'bl_loading_location' => '인천항 3부두',
            'vessel_name' => 'STELLAR ACE',
            'bl_document' => 'documents/bl_A0나0002.pdf',
            'bl_issue_date' => '2025-11-01',
            'dhl_request' => true,
            'dhl_recipient_name' => 'Batbayar Gantulga',
            'dhl_recipient_address' => 'Sukhbaatar District, Ulaanbaatar',
            'dhl_recipient_phone' => '+976-9911-2233',
            'dhl_sender_name' => 'SSANCAR LTD.',
            'dhl_sender_address' => '서울시 강남구 삼성로 100',
            'dhl_weight' => 1.2, 'dhl_dimensions' => '28x18x8',
        ]);
        // 총 판매액 = 12800 + 600 = 13400 USD
        // 계약금 4000 → 잔금1 4000(10-10) → 잔금2 3200(10-22) → 잔금3 2200(11-02) = 13400 → 미수금 0
        FinalPayment::updateOrCreate(['vehicle_id' => $v50->id, 'payment_date' => '2025-10-10'],
            ['vehicle_id' => $v50->id, 'amount' => 4000.00, 'payment_date' => '2025-10-10', 'note' => '1차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v50->id, 'payment_date' => '2025-10-22'],
            ['vehicle_id' => $v50->id, 'amount' => 3200.00, 'payment_date' => '2025-10-22', 'note' => '2차 잔금']);
        FinalPayment::updateOrCreate(['vehicle_id' => $v50->id, 'payment_date' => '2025-11-02'],
            ['vehicle_id' => $v50->id, 'amount' => 2200.00, 'payment_date' => '2025-11-02', 'note' => '3차 잔금 (완납)']);

        // 거래완료 일반 2대
        foreach ([
            ['A0다0003', '현대', '넥쏘',   2022, 0,    1820, 22000, '은색', '2025-08-15', $sm3, $osaka,   $cons($osaka),   1350.00, 17000.00, 850.00, 'BUSJ20251010001', '2025-10-12'],
            ['A0라0004', '기아', '스포티지', 2021, 1591, 1545, 55000, '흰색', '2025-08-20', $sm1, $myanmar, $cons($myanmar), 1342.00, 10000.00, 700.00, 'PTK20251015002',  '2025-10-17'],
        ] as [$no, $brand, $model, $year, $cc, $kg, $km, $color, $pdate, $sm, $buyer, $consig, $rate, $sp, $tf, $blno, $bldate]) {
            $pp = (int) ($sp * $rate * 0.83);
            $v = $this->upsert([
                'vehicle_number' => $no, 'brand' => $brand, 'model_type' => $model,
                'year' => $year, 'cc' => $cc, 'weight_kg' => $kg, 'mileage' => $km, 'color' => $color,
                'sales_channel' => 'export', 'purchase_date' => $pdate, 'salesman_id' => $sm?->id,
                'purchase_from' => '법인 매입',
                'purchase_price' => $pp, 'selling_fee' => 440000, 'cost_towing' => 160000, 'cost_carry' => 100000, 'cost_shoring' => 230000,
                'down_payment' => $pp, 'selling_fee_payment' => 440000,
                'is_deregistered' => true, 'deregistration_document' => "documents/dereg_{$no}.pdf",
                'sale_date' => date('Y-m-d', strtotime($pdate.' +15 days')),
                'currency' => 'USD', 'exchange_rate' => $rate,
                'buyer_id' => $buyer?->id, 'consignee_id' => $consig?->id,
                'sale_price' => $sp, 'transport_fee' => $tf,
                'deposit_down_payment' => $sp,
                'export_buyer_id' => $buyer?->id, 'export_consignee_id' => $consig?->id,
                'forwarding_company_id' => $fwd1?->id,
                'export_declaration_amount' => $sp,
                'shipping_date' => date('Y-m-d', strtotime($pdate.' +45 days')),
                'shipping_method' => 'RORO', 'port_of_loading' => '부산항',
                'export_declaration_document' => "documents/export_decl_{$no}.pdf",
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $buyer?->id, 'bl_consignee_id' => $consig?->id,
                'bl_number' => $blno,
                'bl_loading_location' => '부산신항 2부두',
                'vessel_name' => 'MSC OSCAR',
                'bl_document' => "documents/bl_{$no}.pdf",
                'bl_issue_date' => $bldate,
                'dhl_request' => true,
                'dhl_recipient_name' => $buyer?->contact_name ?? 'Recipient',
                'dhl_recipient_address' => $buyer?->address ?? 'Address',
                'dhl_recipient_phone' => $buyer?->contact_phone ?? '',
                'dhl_sender_name' => 'SSANCAR LTD.',
                'dhl_sender_address' => '서울시 강남구 삼성로 100',
                'dhl_weight' => 1.3, 'dhl_dimensions' => '28x18x8',
            ]);
        }

        // ══════════════════════════════════════════════════════════════
        // 정산 데이터 — 거래완료 차량 일부
        // ══════════════════════════════════════════════════════════════
        foreach (['A0가0001', 'A0나0002', 'A0다0003', 'A0라0004'] as $vno) {
            $vehicle = Vehicle::where('vehicle_number', $vno)->first();
            if (! $vehicle) {
                continue;
            }
            $salesman = Salesman::find($vehicle->salesman_id);
            if (! $salesman) {
                continue;
            }
            Settlement::updateOrCreate(
                ['vehicle_id' => $vehicle->id],
                [
                    'vehicle_id' => $vehicle->id,
                    'salesman_id' => $salesman->id,
                    'settlement_type' => 'ratio',
                    'settlement_ratio' => 30.00,
                    'other_deduction' => 0,
                    'settlement_status' => in_array($vno, ['A0가0001', 'A0나0002']) ? 'confirmed' : 'pending',
                ]
            );
        }

        // 수출통관완료 차량에도 정산 대기 생성
        foreach (['70가7001', '70나7002', '70다7003'] as $vno) {
            $vehicle = Vehicle::where('vehicle_number', $vno)->first();
            if (! $vehicle) {
                continue;
            }
            $salesman = Salesman::find($vehicle->salesman_id);
            if (! $salesman) {
                continue;
            }
            Settlement::updateOrCreate(
                ['vehicle_id' => $vehicle->id],
                [
                    'vehicle_id' => $vehicle->id,
                    'salesman_id' => $salesman->id,
                    'settlement_type' => 'ratio',
                    'settlement_ratio' => 30.00,
                    'other_deduction' => 0,
                    'settlement_status' => 'pending',
                ]
            );
        }

        // 캐시 일괄 재계산
        $this->command->info('진행상태 캐시 재계산 중...');
        Artisan::call('vehicles:rebuild-caches');
        $this->command->info('완료!');
    }

    private function upsert(array $data): Vehicle
    {
        $number = $data['vehicle_number'];
        unset($data['vehicle_number']);

        return Vehicle::updateOrCreate(['vehicle_number' => $number], $data);
    }
}
