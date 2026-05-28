<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 운영(production) 필수 데이터만 시딩. 더미는 절대 넣지 않는다.
 *
 * 포함: 수출 대상국(8) + 항구 마스터(PortSeeder) + 시스템 채널 설정 + 관리자 계정 2개.
 * 더미(바이어/컨사이니/포워딩사/영업담당자/차량/정산 데모)는 DemoSeeder 로 분리됨.
 *
 * DatabaseSeeder 가 환경 무관 항상 호출 → 운영 `php artisan db:seed` 의 단일 진입점.
 *
 * 관리자 계정은 평문 'password' 대신 .env 환경변수에서 읽는다 (config/seeding.php 경유):
 *   - 시스템관리자(super): ADMIN_EMAIL / ADMIN_PASSWORD / ADMIN_NAME(기본 '시스템관리자')
 *   - 최고관리자(admin):   BOSS_EMAIL  / BOSS_PASSWORD  / BOSS_NAME(기본 '최고관리자')
 * email+password 둘 중 하나라도 없으면 해당 계정 생성을 건너뛰고 경고를 출력한다.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CountrySeeder::class);
        $this->call(PortSeeder::class);
        $this->seedSettings();
        $this->seedAdminAccounts();
    }

    private function seedAdminAccounts(): void
    {
        $this->seedAdminFromConfig('seeding.admin', 'super', '시스템관리자(super)', 'ADMIN_EMAIL / ADMIN_PASSWORD');
        $this->seedAdminFromConfig('seeding.boss', 'admin', '최고관리자(admin)', 'BOSS_EMAIL / BOSS_PASSWORD');
    }

    /**
     * config/seeding.php 의 자격증명으로 관리자 1명 생성/갱신.
     * email 또는 password 가 비어 있으면 건너뛰고 경고 (운영에서 미설정 시 안전 정지).
     */
    private function seedAdminFromConfig(string $configKey, string $permission, string $label, string $envHint): void
    {
        $email = config("{$configKey}.email");
        $password = config("{$configKey}.password");

        if (empty($email) || empty($password)) {
            $this->command?->warn(
                "[ProductionSeeder] {$label} 계정 생성 건너뜀 — .env 에 {$envHint} 설정 후 `php artisan config:clear && php artisan db:seed` 재실행하세요."
            );

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config("{$configKey}.name"),
                'permission' => $permission,
                'role' => '관리',           // super/admin 은 role 무관 — '관리'로 통일
                'type' => null,             // type 은 role='영업' 한정
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("[ProductionSeeder] {$label} 계정 생성/갱신 완료: {$email}");
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'heyman_channel_enabled', 'value' => 'true',  'type' => 'boolean', 'description' => '헤이맨 채널 사용 여부'],
            ['key' => 'carpul_channel_enabled', 'value' => 'false', 'type' => 'boolean', 'description' => '카풀 채널 사용 여부'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(['key' => $s['key']], $s);
        }
    }
}
