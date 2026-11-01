<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B 파트너스 1차 + ssancar 포털 2단계 (jin 2026-11-01 확정 — `docs/design/b2b-partners-2026-10-30.md`).
 *
 * - buyers: 특수계약 관리대상 `is_b2b_partner` · 포털 2단계 칸(사진·홈페이지·사무실주소·도시·WhatsApp·공개지도 동의·API 연동 표시).
 * - vehicles: 거래 유형 `deal_type` — 🚫 enum 아님(SKILLS §8 #36). 값 검증은 `Vehicle::DEAL_TYPES`.
 * - buyer_documents: 바이어 계약서(기본약정서·추가약정서) N행. 교체는 새 행 + `superseded_at`(원본 보관 — 요청서 02).
 *
 * 전부 nullable/default 추가 + 새 테이블 — 3-DB 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $t) {
            $t->boolean('is_b2b_partner')->default(false)->after('is_domestic');
            $t->string('website', 200)->nullable()->after('is_b2b_partner');
            $t->string('office_address', 200)->nullable()->after('website');
            $t->string('city', 100)->nullable()->after('office_address');
            $t->string('whatsapp_phone', 30)->nullable()->after('city');
            $t->string('photo_path')->nullable()->after('whatsapp_phone');
            $t->timestamp('photo_updated_at')->nullable()->after('photo_path');
            $t->boolean('map_name_public')->default(false)->after('photo_updated_at');
            $t->boolean('api_linked')->default(false)->after('map_name_public');
        });

        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('deal_type', 2)->nullable()->index()->after('buyer_undecided');
        });

        Schema::create('buyer_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('buyer_id')->constrained()->cascadeOnDelete();
            $t->string('path');                                  // vehicle_docs_disk 상 경로 (buyers/{id}/contracts/…)
            $t->string('kind', 20)->default('base');             // base = 기본약정서 / extra = 추가약정서
            $t->string('note', 200)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('superseded_at')->nullable();          // 교체됨 — 지우지 않고 보관
            $t->timestamps();
            $t->index(['buyer_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_documents');
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropIndex(['deal_type']);
            $t->dropColumn('deal_type');
        });
        Schema::table('buyers', function (Blueprint $t) {
            $t->dropColumn(['is_b2b_partner', 'website', 'office_address', 'city', 'whatsapp_phone',
                'photo_path', 'photo_updated_at', 'map_name_public', 'api_linked']);
        });
    }
};
