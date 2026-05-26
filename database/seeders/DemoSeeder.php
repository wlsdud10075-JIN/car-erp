<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 로컬 개발 전용 더미 데이터. 운영(production)에는 절대 들어가지 않는다.
 *
 * DatabaseSeeder 가 local 환경에서만 호출 (ProductionSeeder 다음).
 * 포함: 로컬 테스트 관리자 + 더미 직원 6 + 바이어/컨사이니/포워딩사/영업담당자
 *       + 차량 50대(VehicleSeeder) + 정산 데모(SettlementDemoSeeder).
 *
 * 로컬 테스트 관리자(admin@car-erp.test / 'password')는 여기서 생성한다.
 * ProductionSeeder 는 .env 환경변수 없으면 관리자를 건너뛰므로, 로컬 편의 로그인
 * 계정은 더미 데이터의 일부로 본 시더가 보장한다.
 *
 * 단독 실행 가능: `php artisan db:seed --class=Database\\Seeders\\DemoSeeder`
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // 단독 실행 대비 — 마스터 데이터(국가/항구/설정)가 없으면 ProductionSeeder 선행.
        // DatabaseSeeder 정상 흐름(Production → Demo)에서는 이미 있어 건너뜀.
        if (Country::count() === 0) {
            $this->call(ProductionSeeder::class);
        }

        $this->seedTestAdmins();
        $this->seedDummyUsers();
        $this->seedBuyersAndConsignees();
        $this->seedForwardingCompanies();
        $this->seedSalesmen();
        $this->call(VehicleSeeder::class);
        $this->call(SettlementDemoSeeder::class);
    }

    /**
     * 로컬 편의 관리자 2개 — 고정 이메일 + 'password'. 운영에선 사용 안 함.
     * (운영 관리자는 ProductionSeeder 가 .env 자격증명으로 생성)
     */
    private function seedTestAdmins(): void
    {
        $admins = [
            ['name' => '시스템관리자', 'email' => 'admin@car-erp.test', 'permission' => 'super', 'role' => '관리'],
            ['name' => '최고관리자',   'email' => 'boss@car-erp.test',  'permission' => 'admin', 'role' => '관리'],
        ];

        foreach ($admins as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                array_merge($data, [
                    'phone' => null,
                    'type' => null,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ])
            );
        }
    }

    /**
     * 더미 직원 6명. seedSalesmen 이 영업 3명(sales/choi/jung)을 email 로 찾아 연결한다.
     * 2026-05-21 — User-Salesman 1:1 강제: 김영업=employee, 최매입·정수출=freelance.
     */
    private function seedDummyUsers(): void
    {
        $users = [
            ['name' => '김영업', 'email' => 'sales@car-erp.test',  'phone' => '010-1111-2222', 'permission' => 'user', 'role' => '영업',     'type' => 'employee'],
            ['name' => '최매입', 'email' => 'choi@car-erp.test',   'phone' => '010-3333-4444', 'permission' => 'user', 'role' => '영업',     'type' => 'freelance'],
            ['name' => '정수출', 'email' => 'jung@car-erp.test',   'phone' => '010-5555-6666', 'permission' => 'user', 'role' => '영업',     'type' => 'freelance'],
            ['name' => '이통관', 'email' => 'clear@car-erp.test',  'phone' => null,            'permission' => 'user', 'role' => '수출통관', 'type' => null],
            ['name' => '김진영', 'email' => 'settle@car-erp.test', 'phone' => null,            'permission' => 'user', 'role' => '재무',     'type' => null],
            ['name' => '박관리', 'email' => 'manage@car-erp.test', 'phone' => null,            'permission' => 'user', 'role' => '관리',     'type' => null],
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
}
