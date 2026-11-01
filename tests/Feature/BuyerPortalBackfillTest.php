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

    public function test_city_is_the_segment_before_the_country_without_postal_code(): void
    {
        $this->assertSame('Podujevë', BuyerPortalBackfill::cityFromAddress('Gllamnik, Podujevë 11000, Kosovë'));   // heymanerp AUTO SCOUT 실물
        $this->assertSame('Prishtina', BuyerPortalBackfill::cityFromAddress('Rr. B, Prishtina 10000, Kosovo'));
        $this->assertSame('Durrës', BuyerPortalBackfill::cityFromAddress('Durrës, Albania'));               // 2토막 = 앞
        $this->assertSame('Tokyo', BuyerPortalBackfill::cityFromAddress('2-1-1 Shibaura, Minato-ku, Tokyo 105-0023, Japan'));
        $this->assertNull(BuyerPortalBackfill::cityFromAddress('Prishtina'), '한 토막은 모른다 — 지어내지 않는다');
        $this->assertNull(BuyerPortalBackfill::cityFromAddress(''));
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
