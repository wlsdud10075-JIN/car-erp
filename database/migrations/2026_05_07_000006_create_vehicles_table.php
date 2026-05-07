<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_number')->unique();
            $table->enum('sales_channel', ['export', 'heyman', 'carpul'])->default('export');
            $table->boolean('is_disposed')->default(false);

            // ── 기본정보 ─────────────────────────────────────────────
            $table->string('brand')->nullable();
            $table->string('model_type')->nullable();
            $table->smallInteger('year')->unsigned()->nullable();
            $table->integer('cc')->nullable();
            $table->integer('weight_kg')->nullable();
            $table->integer('mileage')->nullable();
            $table->string('color')->nullable();

            // ── NICE API 등록정보 12개 ────────────────────────────────
            $table->string('nice_reg_vin')->nullable();           // 차대번호
            $table->string('nice_reg_engine_no')->nullable();     // 원동기형식
            $table->string('nice_reg_fuel_type')->nullable();     // 연료종류
            $table->string('nice_reg_use_type')->nullable();      // 용도
            $table->string('nice_reg_vehicle_form')->nullable();  // 차체형상
            $table->date('nice_reg_first_date')->nullable();      // 최초등록일
            $table->date('nice_reg_date')->nullable();            // 등록일
            $table->string('nice_reg_owner_name')->nullable();    // 소유자명
            $table->text('nice_reg_owner_addr')->nullable();      // 소유자주소
            $table->integer('nice_reg_max_load')->nullable();     // 최대적재량 (kg)
            $table->tinyInteger('nice_reg_passengers')->nullable(); // 승차인원
            $table->string('nice_reg_color')->nullable();         // 색상

            // ── NICE API 제원정보 12개 ────────────────────────────────
            $table->string('nice_spec_maker')->nullable();             // 제조사
            $table->string('nice_spec_model')->nullable();             // 모델명
            $table->string('nice_spec_year')->nullable();              // 연식
            $table->integer('nice_spec_displacement')->nullable();     // 배기량 (cc)
            $table->string('nice_spec_transmission')->nullable();      // 변속기
            $table->string('nice_spec_drive_type')->nullable();        // 구동방식
            $table->integer('nice_spec_length')->nullable();           // 전장 (mm)
            $table->integer('nice_spec_width')->nullable();            // 전폭 (mm)
            $table->integer('nice_spec_height')->nullable();           // 전고 (mm)
            $table->integer('nice_spec_wheelbase')->nullable();        // 축거 (mm)
            $table->integer('nice_spec_curb_weight')->nullable();      // 공차중량 (kg)
            $table->string('nice_spec_fuel_efficiency')->nullable();   // 연비 (km/L)

            // ── 매입 ─────────────────────────────────────────────────
            $table->date('purchase_date')->nullable();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->string('purchase_from')->nullable();           // 구입처
            $table->unsignedBigInteger('purchase_price')->default(0);  // 매입가 (원)
            $table->unsignedBigInteger('selling_fee')->default(0);     // 매도비 (원)
            $table->unsignedBigInteger('cost_deregistration')->default(0); // 말소비
            $table->unsignedBigInteger('cost_license')->default(0);        // 면허비
            $table->unsignedBigInteger('cost_towing')->default(0);         // 탁송비
            $table->unsignedBigInteger('cost_carry')->default(0);          // 캐리비
            $table->unsignedBigInteger('cost_shoring')->default(0);        // 쇼링비
            $table->unsignedBigInteger('cost_insurance')->default(0);      // 보험료
            $table->unsignedBigInteger('cost_transfer')->default(0);       // 이전비
            $table->unsignedBigInteger('cost_extra1')->default(0);         // 기타비1
            $table->unsignedBigInteger('cost_extra2')->default(0);         // 기타비2
            $table->unsignedBigInteger('down_payment')->default(0);        // 매입 계약금 지급
            $table->unsignedBigInteger('selling_fee_payment')->default(0); // 매도비 지급
            $table->text('purchase_remittance_memo')->nullable();
            $table->boolean('is_deregistered')->default(false);
            $table->string('deregistration_document')->nullable(); // 파일경로

            // ── 판매 ─────────────────────────────────────────────────
            $table->date('sale_date')->nullable();
            $table->enum('currency', ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'])->default('USD');
            $table->decimal('exchange_rate', 12, 4)->default(0);
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('consignee_id')->nullable()->constrained('consignees')->nullOnDelete();
            $table->decimal('sale_price', 15, 2)->default(0);    // 판매가 (currency 기준)
            $table->decimal('tax_dc', 15, 2)->default(0);        // TAX D/C
            $table->decimal('commission', 15, 2)->default(0);    // Commission
            $table->decimal('transport_fee', 15, 2)->default(0); // 운임비 (USD)
            $table->decimal('auto_loading', 15, 2)->default(0);  // 자동하역비
            $table->decimal('sale_other_costs', 15, 2)->default(0); // 기타 판매비용
            $table->decimal('deposit_down_payment', 15, 2)->default(0); // 판매 계약금 입금
            $table->decimal('interim_payment', 15, 2)->default(0);     // 중도금
            $table->decimal('advance_payment1', 15, 2)->default(0);    // 선수금1
            $table->decimal('advance_payment2', 15, 2)->default(0);    // 선수금2
            $table->decimal('savings_used', 15, 2)->default(0);        // 적립금 사용액

            // ── 수출통관 ─────────────────────────────────────────────
            $table->foreignId('export_buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('export_consignee_id')->nullable()->constrained('consignees')->nullOnDelete();
            $table->foreignId('forwarding_company_id')->nullable()->constrained('forwarding_companies')->nullOnDelete();
            $table->decimal('export_declaration_amount', 15, 2)->nullable(); // 면장금액 (USD)
            $table->date('shipping_date')->nullable();
            $table->date('eta_date')->nullable();                 // ETA
            $table->enum('shipping_method', ['RORO', 'CONTAINER'])->nullable();
            $table->string('port_of_loading')->nullable();        // 선적항
            $table->string('export_declaration_document')->nullable(); // 수출신고서 파일경로
            $table->boolean('is_export_cleared')->default(false);
            $table->boolean('forwarding_email_sent')->default(false);

            // ── 선적 (B/L) ───────────────────────────────────────────
            $table->foreignId('bl_buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('bl_consignee_id')->nullable()->constrained('consignees')->nullOnDelete();
            $table->string('bl_number')->nullable();
            $table->string('container_number')->nullable();
            $table->string('bl_loading_location')->nullable();   // 반입지
            $table->string('vessel_name')->nullable();            // VSL
            $table->string('bl_document')->nullable();            // B/L 파일경로
            $table->date('bl_issue_date')->nullable();

            // ── DHL ──────────────────────────────────────────────────
            $table->string('dhl_recipient_name')->nullable();
            $table->text('dhl_recipient_address')->nullable();
            $table->string('dhl_recipient_phone')->nullable();
            $table->string('dhl_sender_name')->nullable();
            $table->text('dhl_sender_address')->nullable();
            $table->decimal('dhl_weight', 8, 2)->nullable();
            $table->string('dhl_dimensions')->nullable();         // W×H×L (cm)
            $table->boolean('dhl_request')->default(false);

            $table->text('memo')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sales_channel');
            $table->index('purchase_date');
            $table->index('sale_date');
            $table->index('shipping_date');
            $table->index('bl_issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
