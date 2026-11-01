<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Support\BuyerPortalBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 🗺️ 바이어 포털 칸 일괄 채움 (jin 2026-11-01) — 빈 칸만 · 도시는 지어내지 않는다 · dry-run 은 무변경. */
class BuyerPortalBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** 실측 주소 형식(heymanerp·ssancarerp 2026-11-01)으로 — 맞는 것은 맞게, 모르는 것은 **null**(지어내지 않는다). */
    public function test_city_extraction_on_real_address_shapes(): void
    {
        foreach ([
            'Gllamnik, Podujevë 11000, Kosovë' => 'Podujevë',          // heymanerp AUTO SCOUT
            'Coloradostrasse 7, 27580 Bremerhaven' => 'Bremerhaven',     // 독일식 「우편번호 도시」, 국가 없음
            'JŪROS g 33, Tauragė Lithuania' => 'Tauragė',                // 국가가 같은 토막 끝에
            'Llazareve, nn - 15000 Obiliq, Kosovo' => 'Obiliq',          // 번지 없음(nn) + 우편번호
            'Parmentierplein 13, 3088 GN Rotterdam, Netherlands' => 'Rotterdam',   // 네덜란드식 우편번호
            'Kosovo,Xhelal Hajda, p.n. Rahovec.' => 'Rahovec',           // 국가가 앞 토막
            'Albert-Bote-Straße 11, 28197 Bremen' => 'Bremen',
            'GORGASALI 10/1 , GEORGIA  POTI' => 'POTI',                  // 슬래시 토막 + 국가 단어 앞
            'Abdyl Frashëri, 8, Prishtine, Kosovo.' => 'Prishtine',
            'V O R Ë , Tirana , Albania' => 'Tirana',
            'Am Huevel 21, 47877, Willich, Germany' => 'Willich',
            'Durrës, Albania' => 'Durrës',
            '2-1-1 Shibaura, Minato-ku, Tokyo 105-0023, Japan' => 'Tokyo',
            'ABI QARSHIA,PRIZREN,KOSOVO' => 'PRIZREN',
            'Prishtine, Kosovo.' => 'Prishtine',
            'Prishtine, Kosovo. ' => 'Prishtine',                 // NBSP 섞인 실데이터(heymanerp AUTO DIOR)
            // 모르는 것 — 틀린 도시보다 빈 도시
            'Prishtina' => null,                                         // 한 토막(국가 없음)
            'KOSOVO' => null,                                            // 국가뿐
            'cerrik elbasan' => null,
            'DURRES, SHIJAK, OBJEKT ME NR. PASURIE 15 39' => null,       // 마지막 토막이 건물·번지
            'Albania, Lac, Rruga Palaj nr 39' => null,                   // 마지막 토막이 거리
            'SHQIPTARE, ALBANIA' => null,                                // 「알바니아의」 = 국가 별칭
            'CWS-1V-225818 26th Floor, Amber Gem Tower, Ajman' => 'Ajman',
            '서울시 강남구 테헤란로 123' => null,
            '' => null,
        ] as $address => $want) {
            $this->assertSame($want, BuyerPortalBackfill::cityFromAddress($address), "주소 '{$address}'");
        }
        $this->assertNull(BuyerPortalBackfill::cityFromAddress(null));
    }

    public function test_plan_fills_only_empty_fields_and_whatsapp_is_opt_in(): void
    {
        $b = Buyer::create(['name' => 'AUTO SCOUT', 'is_active' => true, 'address' => 'Gllamnik, Podujevë 11000, Kosovë', 'contact_phone' => '+38348188180']);
        $this->assertSame(['office_address' => 'Gllamnik, Podujevë 11000, Kosovë', 'city' => 'Podujevë'], BuyerPortalBackfill::plan($b));
        $this->assertSame('+38348188180', BuyerPortalBackfill::plan($b, true)['whatsapp_phone']);

        $filled = Buyer::create(['name' => 'FILLED', 'is_active' => true, 'address' => 'X, Y, Z', 'contact_phone' => '1', 'office_address' => 'keep', 'city' => 'keep', 'whatsapp_phone' => 'keep']);
        $this->assertSame([], BuyerPortalBackfill::plan($filled, true), '이미 있는 값은 안 건드린다');
    }

    public function test_command_is_dry_run_by_default_and_apply_writes_with_audit(): void
    {
        $b = Buyer::create(['name' => 'AUTO SCOUT', 'is_active' => true, 'address' => 'Gllamnik, Podujevë 11000, Kosovë', 'contact_phone' => '+38348188180']);

        $this->artisan('buyers:backfill-portal-info')->assertSuccessful();
        $this->assertNull($b->fresh()->office_address, 'dry-run 은 아무것도 안 바꾼다');

        $this->artisan('buyers:backfill-portal-info --apply')->assertSuccessful();
        $b->refresh();
        $this->assertSame('Gllamnik, Podujevë 11000, Kosovë', $b->office_address);
        $this->assertSame('Podujevë', $b->city);
        $this->assertNull($b->whatsapp_phone, '--whatsapp 없이는 안 복사');
        $this->assertSame(2, AuditLog::where('auditable_id', $b->id)->where('action', 'buyers_portal_backfill')->count());

        $this->artisan('buyers:backfill-portal-info --apply --whatsapp')->assertSuccessful();
        $this->assertSame('+38348188180', $b->fresh()->whatsapp_phone);
    }
}
