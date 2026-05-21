<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();
        $this->seedSettings();
        $this->seedCountries();
        $this->seedBuyersAndConsignees();
        $this->seedForwardingCompanies();
        $this->seedSalesmen();
        $this->call(PortSeeder::class);
        $this->call(VehicleSeeder::class);
    }

    private function seedUsers(): void
    {
        // 큐 14-1 — '전체' role 삭제. admin/super는 role 무관이라 '관리'로 통일.
        // 박전체 → 박관리 (서브관리자) 교체.
        // 2026-05-21 — User-Salesman 1:1 강제. 기존 user_id=null 더미 salesman(최매입/정수출) 도 User 생성.
        //   김영업 = employee (사내직원), 최매입/정수출 = freelance (프리랜서). 'type' 은 role='영업' 한정.
        $users = [
            ['name' => '시스템관리자', 'email' => 'admin@car-erp.test',    'phone' => null,            'permission' => 'super',  'role' => '관리',     'type' => null],
            ['name' => '최고관리자',   'email' => 'boss@car-erp.test',     'phone' => null,            'permission' => 'admin',  'role' => '관리',     'type' => null],
            ['name' => '김영업',       'email' => 'sales@car-erp.test',    'phone' => '010-1111-2222', 'permission' => 'user',   'role' => '영업',     'type' => 'employee'],
            ['name' => '최매입',       'email' => 'choi@car-erp.test',     'phone' => '010-3333-4444', 'permission' => 'user',   'role' => '영업',     'type' => 'freelance'],
            ['name' => '정수출',       'email' => 'jung@car-erp.test',     'phone' => '010-5555-6666', 'permission' => 'user',   'role' => '영업',     'type' => 'freelance'],
            ['name' => '이통관',       'email' => 'clear@car-erp.test',    'phone' => null,            'permission' => 'user',   'role' => '수출통관', 'type' => null],
            ['name' => '김진영',       'email' => 'settle@car-erp.test',   'phone' => null,            'permission' => 'user',   'role' => '재무',     'type' => null],
            ['name' => '박관리',       'email' => 'manage@car-erp.test',   'phone' => null,            'permission' => 'user',   'role' => '관리',     'type' => null],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ])
            );
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'heyman_channel_enabled', 'value' => 'true',  'type' => 'boolean', 'description' => '헤이맨 채널 사용 여부'],
            ['key' => 'carpul_channel_enabled',  'value' => 'false', 'type' => 'boolean', 'description' => '카풀 채널 사용 여부'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(['key' => $s['key']], $s);
        }
    }

    private function seedCountries(): void
    {
        $countries = [
            ['name' => '일본',       'code' => 'JPN', 'currency' => 'JPY'],
            ['name' => '몽골',       'code' => 'MNG', 'currency' => 'USD'],
            ['name' => '미얀마',     'code' => 'MMR', 'currency' => 'USD'],
            ['name' => '카자흐스탄', 'code' => 'KAZ', 'currency' => 'USD'],
            ['name' => '중국',       'code' => 'CHN', 'currency' => 'CNY'],
            ['name' => 'UAE',        'code' => 'ARE', 'currency' => 'USD'],
            ['name' => '러시아',     'code' => 'RUS', 'currency' => 'USD'],
            ['name' => '한국',       'code' => 'KOR', 'currency' => 'KRW'],
        ];

        foreach ($countries as $c) {
            Country::updateOrCreate(['code' => $c['code']], $c);
        }
    }

    private function seedBuyersAndConsignees(): void
    {
        $japan = Country::where('code', 'JPN')->first();
        $mongolia = Country::where('code', 'MNG')->first();
        $myanmar = Country::where('code', 'MMR')->first();
        $korea = Country::where('code', 'KOR')->first();

        $buyers = [
            [
                'name' => 'TOKYO AUTO TRADING',
                'country_id' => $japan->id,
                'contact_name' => 'Tanaka Hiroshi',
                'contact_email' => 'tanaka@tokyoauto.jp',
                'contact_phone' => '+81-3-1234-5678',
                'address' => '1-2-3 Shibuya, Tokyo, Japan',
            ],
            [
                'name' => 'OSAKA MOTORS CO.',
                'country_id' => $japan->id,
                'contact_name' => 'Yamamoto Kenji',
                'contact_email' => 'yamamoto@osakamotor.jp',
                'contact_phone' => '+81-6-9876-5432',
                'address' => '5-10 Namba, Osaka, Japan',
            ],
            [
                'name' => 'MONGOLIA BEST CAR',
                'country_id' => $mongolia->id,
                'contact_name' => 'Batbayar Gantulga',
                'contact_email' => 'gantulga@mnbestcar.mn',
                'contact_phone' => '+976-9911-2233',
                'address' => 'Sukhbaatar District, Ulaanbaatar, Mongolia',
            ],
            [
                'name' => 'UB AUTO GROUP',
                'country_id' => $mongolia->id,
                'contact_name' => 'Enkhjargal Dorj',
                'contact_email' => 'enkh@ubauto.mn',
                'contact_phone' => '+976-9955-4477',
                'address' => 'Khan-Uul District, Ulaanbaatar, Mongolia',
            ],
            [
                'name' => 'YANGON CAR IMPORT',
                'country_id' => $myanmar->id,
                'contact_name' => 'Aung Ko Win',
                'contact_email' => 'aungkowin@yangoncar.mm',
                'contact_phone' => '+95-9-4444-5555',
                'address' => 'Lanmadaw Township, Yangon, Myanmar',
            ],
            [
                'name' => '헤이맨모터스',
                'country_id' => $korea->id,
                'contact_name' => '홍길동',
                'contact_email' => 'hong@heyman.co.kr',
                'contact_phone' => '010-1234-5678',
                'address' => '서울시 강남구 테헤란로 123',
            ],
        ];

        foreach ($buyers as $data) {
            $buyer = Buyer::updateOrCreate(['name' => $data['name']], array_merge($data, ['is_active' => true]));

            // 바이어별 컨사이니 생성
            Consignee::updateOrCreate(
                ['name' => $data['name'].' CONSIGNEE'],
                [
                    'name' => $data['name'].' CONSIGNEE',
                    'buyer_id' => $buyer->id,
                    'country_id' => $data['country_id'],
                    'contact_name' => $data['contact_name'],
                    'contact_email' => $data['contact_email'],
                    'contact_phone' => $data['contact_phone'],
                    'address' => $data['address'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedForwardingCompanies(): void
    {
        $companies = [
            [
                'name' => '부산포워딩㈜',
                'contact_name' => '김포워',
                'email' => 'info@busanfwd.co.kr',
                'phone' => '051-123-4567',
                'address' => '부산시 중구 중앙대로 100',
            ],
            [
                'name' => '인천국제물류',
                'contact_name' => '이물류',
                'email' => 'ops@icnlogistics.co.kr',
                'phone' => '032-987-6543',
                'address' => '인천시 연수구 컨벤시아대로 50',
            ],
            [
                'name' => 'GLOBAL FREIGHT KOREA',
                'contact_name' => 'Park Soobin',
                'email' => 'soobin@globalfreight.kr',
                'phone' => '02-555-7890',
                'address' => '서울시 종로구 세종대로 110',
            ],
        ];

        foreach ($companies as $data) {
            ForwardingCompany::updateOrCreate(['name' => $data['name']], array_merge($data, ['is_active' => true]));
        }
    }

    private function seedSalesmen(): void
    {
        // 2026-05-21 — User-Salesman 1:1 강제. 모든 영업담당자에 User 연결 + user.type 을 salesman.type 으로 미러링.
        $userByEmail = User::whereIn('email', ['sales@car-erp.test', 'choi@car-erp.test', 'jung@car-erp.test'])
            ->get()
            ->keyBy('email');

        $salesmen = [
            ['name' => '김영업', 'phone' => '010-1111-2222', 'email' => 'sales@car-erp.test'],
            ['name' => '최매입', 'phone' => '010-3333-4444', 'email' => 'choi@car-erp.test'],
            ['name' => '정수출', 'phone' => '010-5555-6666', 'email' => 'jung@car-erp.test'],
        ];

        foreach ($salesmen as $data) {
            $linkedUser = $userByEmail->get($data['email']);
            $data['user_id'] = $linkedUser?->id;
            $data['type'] = $linkedUser?->type;     // user.type 미러링 (Vehicle::saved 훅 호환)
            $data['phone'] = $linkedUser?->phone ?? $data['phone'];  // 2026-05-21 — user.phone 우선
            Salesman::updateOrCreate(['name' => $data['name']], array_merge($data, ['is_active' => true]));
        }
    }

    private function seedVehicles(): void
    {
        $buyers = Buyer::all()->keyBy('name');
        $consignees = Consignee::all();
        $forwardings = ForwardingCompany::all();
        $salesmen = Salesman::all();

        $tokyoBuyer = $buyers['TOKYO AUTO TRADING'] ?? null;
        $osakaBuyer = $buyers['OSAKA MOTORS CO.'] ?? null;
        $mongolBuyer = $buyers['MONGOLIA BEST CAR'] ?? null;
        $mongolBuyer2 = $buyers['UB AUTO GROUP'] ?? null;
        $myanmarBuyer = $buyers['YANGON CAR IMPORT'] ?? null;
        $heymanBuyer = $buyers['헤이맨모터스'] ?? null;

        $tokyoCons = $consignees->where('buyer_id', $tokyoBuyer?->id)->first();
        $osakaCons = $consignees->where('buyer_id', $osakaBuyer?->id)->first();
        $mongolCons = $consignees->where('buyer_id', $mongolBuyer?->id)->first();
        $mongol2Cons = $consignees->where('buyer_id', $mongolBuyer2?->id)->first();
        $myanmarCons = $consignees->where('buyer_id', $myanmarBuyer?->id)->first();

        $fwd1 = $forwardings->get(0);
        $fwd2 = $forwardings->get(1);
        $sm1 = $salesmen->get(0);
        $sm2 = $salesmen->get(1);
        $sm3 = $salesmen->get(2);

        $vehicles = [
            // 1. 매입중
            [
                'vehicle_number' => '12가1001',
                'brand' => '현대', 'model_type' => '쏘나타', 'year' => 2018,
                'cc' => 1999, 'weight_kg' => 1470, 'mileage' => 85000, 'color' => '흰색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-04-20', 'salesman_id' => $sm1?->id,
                'purchase_from' => '현대중고차 강남점',
            ],
            // 2. 매입완료
            [
                'vehicle_number' => '34나2002',
                'brand' => '기아', 'model_type' => 'K5', 'year' => 2019,
                'cc' => 1591, 'weight_kg' => 1380, 'mileage' => 62000, 'color' => '검정',
                'sales_channel' => 'export',
                'purchase_date' => '2026-04-15', 'salesman_id' => $sm1?->id,
                'purchase_from' => '기아직영 중고',
                'purchase_price' => 9500000, 'selling_fee' => 300000,
                'down_payment' => 9500000, 'selling_fee_payment' => 300000,
            ],
            // 3. 말소완료
            [
                'vehicle_number' => '56다3003',
                'brand' => '쌍용', 'model_type' => '렉스턴', 'year' => 2017,
                'cc' => 2157, 'weight_kg' => 1990, 'mileage' => 120000, 'color' => '은색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-04-10', 'salesman_id' => $sm2?->id,
                'purchase_from' => '개인 매입',
                'purchase_price' => 7000000, 'selling_fee' => 200000,
                'down_payment' => 7000000, 'selling_fee_payment' => 200000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_3003.pdf',
            ],
            // 4. 판매중 (export, USD)
            [
                'vehicle_number' => '78라4004',
                'brand' => '현대', 'model_type' => '팰리세이드', 'year' => 2020,
                'cc' => 2199, 'weight_kg' => 2060, 'mileage' => 55000, 'color' => '흰색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-03-25', 'salesman_id' => $sm1?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => 18000000, 'selling_fee' => 500000,
                'down_payment' => 18000000, 'selling_fee_payment' => 500000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_4004.pdf',
                'sale_date' => '2026-04-01',
                'currency' => 'USD', 'exchange_rate' => 1350.00,
                'buyer_id' => $tokyoBuyer?->id, 'consignee_id' => $tokyoCons?->id,
                'sale_price' => 13500.00, 'transport_fee' => 800.00,
                'deposit_down_payment' => 5000.00,
            ],
            // 5. 판매완료 (export, USD)
            [
                'vehicle_number' => '90마5005',
                'brand' => '기아', 'model_type' => '쏘렌토', 'year' => 2019,
                'cc' => 1999, 'weight_kg' => 1845, 'mileage' => 78000, 'color' => '회색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-03-10', 'salesman_id' => $sm2?->id,
                'purchase_from' => '딜러 매입',
                'purchase_price' => 14000000, 'selling_fee' => 400000,
                'down_payment' => 14000000, 'selling_fee_payment' => 400000,
                'cost_towing' => 150000, 'cost_insurance' => 80000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_5005.pdf',
                'sale_date' => '2026-03-20',
                'currency' => 'USD', 'exchange_rate' => 1340.00,
                'buyer_id' => $mongolBuyer?->id, 'consignee_id' => $mongolCons?->id,
                'sale_price' => 11000.00, 'transport_fee' => 600.00,
                'deposit_down_payment' => 4000.00,
            ],
            // 6. 수출통관중
            [
                'vehicle_number' => '11바6006',
                'brand' => '현대', 'model_type' => '투싼', 'year' => 2021,
                'cc' => 1598, 'weight_kg' => 1580, 'mileage' => 38000, 'color' => '파랑',
                'sales_channel' => 'export',
                'purchase_date' => '2026-02-20', 'salesman_id' => $sm3?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => 16500000, 'selling_fee' => 450000,
                'down_payment' => 16500000, 'selling_fee_payment' => 450000,
                'cost_deregistration' => 50000, 'cost_towing' => 120000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_6006.pdf',
                'sale_date' => '2026-03-01',
                'currency' => 'USD', 'exchange_rate' => 1355.00,
                'buyer_id' => $osakaBuyer?->id, 'consignee_id' => $osakaCons?->id,
                'sale_price' => 14200.00, 'transport_fee' => 750.00,
                'deposit_down_payment' => 14200.00,
                'export_buyer_id' => $osakaBuyer?->id, 'export_consignee_id' => $osakaCons?->id,
                'forwarding_company_id' => $fwd1?->id,
                'shipping_date' => '2026-04-15',
            ],
            // 7. 수출통관완료
            [
                'vehicle_number' => '22사7007',
                'brand' => '기아', 'model_type' => '스포티지', 'year' => 2020,
                'cc' => 1591, 'weight_kg' => 1545, 'mileage' => 47000, 'color' => '빨강',
                'sales_channel' => 'export',
                'purchase_date' => '2026-02-05', 'salesman_id' => $sm1?->id,
                'purchase_from' => '개인 매입',
                'purchase_price' => 13000000, 'selling_fee' => 350000,
                'down_payment' => 13000000, 'selling_fee_payment' => 350000,
                'cost_towing' => 100000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_7007.pdf',
                'sale_date' => '2026-02-20',
                'currency' => 'USD', 'exchange_rate' => 1345.00,
                'buyer_id' => $mongolBuyer2?->id, 'consignee_id' => $mongol2Cons?->id,
                'sale_price' => 10500.00, 'transport_fee' => 550.00,
                'deposit_down_payment' => 10500.00,
                'export_buyer_id' => $mongolBuyer2?->id, 'export_consignee_id' => $mongol2Cons?->id,
                'forwarding_company_id' => $fwd2?->id,
                'export_declaration_amount' => 10500.00,
                'shipping_date' => '2026-03-20',
                'export_declaration_document' => 'documents/export_declaration_7007.pdf',
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
            ],
            // 8. 선적중
            [
                'vehicle_number' => '33아8008',
                'brand' => '현대', 'model_type' => '싼타페', 'year' => 2018,
                'cc' => 2199, 'weight_kg' => 1910, 'mileage' => 95000, 'color' => '검정',
                'sales_channel' => 'export',
                'purchase_date' => '2026-01-15', 'salesman_id' => $sm2?->id,
                'purchase_from' => '법인 매입',
                'purchase_price' => 12000000, 'selling_fee' => 350000,
                'down_payment' => 12000000, 'selling_fee_payment' => 350000,
                'cost_towing' => 130000, 'cost_shoring' => 200000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_8008.pdf',
                'sale_date' => '2026-01-25',
                'currency' => 'USD', 'exchange_rate' => 1330.00,
                'buyer_id' => $myanmarBuyer?->id, 'consignee_id' => $myanmarCons?->id,
                'sale_price' => 9800.00, 'transport_fee' => 900.00,
                'deposit_down_payment' => 9800.00,
                'export_buyer_id' => $myanmarBuyer?->id, 'export_consignee_id' => $myanmarCons?->id,
                'forwarding_company_id' => $fwd1?->id,
                'export_declaration_amount' => 9800.00,
                'shipping_date' => '2026-02-28',
                'shipping_method' => 'CONTAINER',
                'port_of_loading' => '부산항',
                'export_declaration_document' => 'documents/export_declaration_8008.pdf',
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $myanmarBuyer?->id, 'bl_consignee_id' => $myanmarCons?->id,
                'bl_loading_location' => '부산신항 3부두',
                'vessel_name' => 'EVER GIVEN',
            ],
            // 9. 선적완료
            [
                'vehicle_number' => '44자9009',
                'brand' => '쌍용', 'model_type' => '코란도', 'year' => 2019,
                'cc' => 1496, 'weight_kg' => 1560, 'mileage' => 68000, 'color' => '흰색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-01-05', 'salesman_id' => $sm3?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => 8500000, 'selling_fee' => 250000,
                'down_payment' => 8500000, 'selling_fee_payment' => 250000,
                'cost_towing' => 100000, 'cost_carry' => 80000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_9009.pdf',
                'sale_date' => '2026-01-15',
                'currency' => 'USD', 'exchange_rate' => 1320.00,
                'buyer_id' => $tokyoBuyer?->id, 'consignee_id' => $tokyoCons?->id,
                'sale_price' => 7200.00, 'transport_fee' => 500.00,
                'deposit_down_payment' => 7200.00,
                'export_buyer_id' => $tokyoBuyer?->id, 'export_consignee_id' => $tokyoCons?->id,
                'forwarding_company_id' => $fwd2?->id,
                'export_declaration_amount' => 7200.00,
                'shipping_date' => '2026-02-10',
                'shipping_method' => 'RORO',
                'port_of_loading' => '평택항',
                'export_declaration_document' => 'documents/export_declaration_9009.pdf',
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $tokyoBuyer?->id, 'bl_consignee_id' => $tokyoCons?->id,
                'bl_number' => 'BUSJ20260210001',
                'bl_loading_location' => '평택항 2게이트',
                'vessel_name' => 'PIONEER',
                'bl_document' => 'documents/bl_9009.pdf',
                'bl_issue_date' => '2026-02-12',
            ],
            // 10. 거래완료
            [
                'vehicle_number' => '55차0010',
                'brand' => '기아', 'model_type' => '카니발', 'year' => 2017,
                'cc' => 2199, 'weight_kg' => 2095, 'mileage' => 140000, 'color' => '검정',
                'sales_channel' => 'export',
                'purchase_date' => '2025-12-01', 'salesman_id' => $sm1?->id,
                'purchase_from' => '개인 매입',
                'purchase_price' => 8000000, 'selling_fee' => 200000,
                'down_payment' => 8000000, 'selling_fee_payment' => 200000,
                'cost_towing' => 120000, 'cost_carry' => 90000, 'cost_shoring' => 180000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_0010.pdf',
                'sale_date' => '2025-12-15',
                'currency' => 'USD', 'exchange_rate' => 1310.00,
                'buyer_id' => $mongolBuyer?->id, 'consignee_id' => $mongolCons?->id,
                'sale_price' => 6500.00, 'transport_fee' => 450.00,
                'deposit_down_payment' => 6500.00,
                'export_buyer_id' => $mongolBuyer?->id, 'export_consignee_id' => $mongolCons?->id,
                'forwarding_company_id' => $fwd1?->id,
                'export_declaration_amount' => 6500.00,
                'shipping_date' => '2026-01-10',
                'shipping_method' => 'RORO',
                'port_of_loading' => '인천항',
                'export_declaration_document' => 'documents/export_declaration_0010.pdf',
                'is_export_cleared' => true, 'forwarding_email_sent' => true,
                'bl_buyer_id' => $mongolBuyer?->id, 'bl_consignee_id' => $mongolCons?->id,
                'bl_number' => 'ICN20260110005',
                'bl_loading_location' => '인천항 7부두',
                'vessel_name' => 'STELLAR ACE',
                'bl_document' => 'documents/bl_0010.pdf',
                'bl_issue_date' => '2026-01-12',
                'dhl_request' => true,
                'dhl_recipient_name' => 'Batbayar Gantulga',
                'dhl_recipient_address' => 'Sukhbaatar District, Ulaanbaatar',
                'dhl_recipient_phone' => '+976-9911-2233',
                'dhl_sender_name' => 'SSANCAR LTD.',
                'dhl_sender_address' => '서울시 강남구 삼성로 100',
                'dhl_weight' => 1.5,
                'dhl_dimensions' => '30x20x10',
            ],
            // 11. 큐 16 — 헤이맨 채널 → export 단일화
            [
                'vehicle_number' => '66카1011',
                'brand' => '현대', 'model_type' => '아반떼', 'year' => 2020,
                'cc' => 1591, 'weight_kg' => 1270, 'mileage' => 42000, 'color' => '흰색',
                'sales_channel' => 'export',
                'purchase_date' => '2026-04-01', 'salesman_id' => $sm2?->id,
                'purchase_from' => '경매 낙찰',
                'purchase_price' => 11000000, 'selling_fee' => 300000,
                'down_payment' => 11000000, 'selling_fee_payment' => 300000,
                'is_deregistered' => true, 'deregistration_document' => 'documents/deregistration_1011.pdf',
                'sale_date' => '2026-04-10',
                'currency' => 'KRW', 'exchange_rate' => 1,
                'buyer_id' => $heymanBuyer?->id,
                'sale_price' => 13500000,
                'deposit_down_payment' => 5000000,
            ],
            // 큐 17 — 12번 시드(폐기 차량) 제거. 폐기 컨셉 운영상 없음.
        ];

        // 22-A-3b (2026-05-20) — vehicles 4컬럼 DROP 후 호환. 4 항목 키가 있으면 type별 confirmed FP 자동 생성.
        $sale4Map = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'advance_2',
        ];

        // 22-C-F (2026-05-20) — vehicles 2컬럼 DROP 후 호환. 2 항목 키가 있으면 type별 confirmed PBP 자동 생성.
        $purchase2Map = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];

        foreach ($vehicles as $data) {
            $fpInserts = [];
            foreach ($sale4Map as $col => $type) {
                if (array_key_exists($col, $data)) {
                    if ((float) $data[$col] > 0) {
                        $fpInserts[] = ['amount' => (float) $data[$col], 'type' => $type];
                    }
                    unset($data[$col]);
                }
            }
            $pbpInserts = [];
            foreach ($purchase2Map as $col => $type) {
                if (array_key_exists($col, $data)) {
                    if ((float) $data[$col] > 0) {
                        $pbpInserts[] = ['amount' => (float) $data[$col], 'type' => $type];
                    }
                    unset($data[$col]);
                }
            }

            $vehicle = Vehicle::updateOrCreate(['vehicle_number' => $data['vehicle_number']], $data);

            foreach ($fpInserts as $row) {
                $vehicle->finalPayments()->updateOrCreate(
                    ['type' => $row['type'], 'note' => '시드 — '.$row['type']],
                    ['amount' => $row['amount'], 'confirmed_at' => $vehicle->created_at ?? now()]
                );
            }
            if (! empty($pbpInserts)) {
                PurchaseBalancePayment::$skipCreatingGuard = true;
                try {
                    foreach ($pbpInserts as $row) {
                        $vehicle->purchaseBalancePayments()->updateOrCreate(
                            ['type' => $row['type'], 'note' => '시드 — '.$row['type']],
                            [
                                'amount' => $row['amount'],
                                'payment_date' => $vehicle->purchase_date ?? now()->subDay()->toDateString(),
                                'confirmed_at' => $vehicle->created_at ?? now(),
                            ]
                        );
                    }
                } finally {
                    PurchaseBalancePayment::$skipCreatingGuard = false;
                }
            }
        }

        // 판매중(#4) 차량에 잔금 추가
        $v4 = Vehicle::where('vehicle_number', '78라4004')->first();
        if ($v4) {
            FinalPayment::updateOrCreate(
                ['vehicle_id' => $v4->id, 'payment_date' => '2026-05-01'],
                ['vehicle_id' => $v4->id, 'amount' => 4250.00, 'payment_date' => '2026-05-01', 'note' => '중도금']
            );
        }

        // 헤이맨(#11) 차량에 잔금 추가
        $v11 = Vehicle::where('vehicle_number', '66카1011')->first();
        if ($v11) {
            FinalPayment::updateOrCreate(
                ['vehicle_id' => $v11->id, 'payment_date' => '2026-04-20'],
                ['vehicle_id' => $v11->id, 'amount' => 4250000, 'payment_date' => '2026-04-20', 'note' => '중도금']
            );
        }

        // 수출통관완료(#7) 차량 정산 데이터
        $v7 = Vehicle::where('vehicle_number', '22사7007')->first();
        $sm = Salesman::where('name', '김영업')->first();
        if ($v7 && $sm) {
            Settlement::updateOrCreate(
                ['vehicle_id' => $v7->id, 'salesman_id' => $sm->id],
                [
                    'vehicle_id' => $v7->id,
                    'salesman_id' => $sm->id,
                    'settlement_type' => 'ratio',
                    'settlement_ratio' => 30.00,
                    'other_deduction' => 0,
                    'settlement_status' => 'confirmed',
                    'confirmed_at' => now(),
                ]
            );
        }
    }
}
