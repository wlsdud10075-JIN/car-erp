<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 판매 — Proforma Invoice. 단일차량. 수출 채널 전용(컨트롤러 EXPORT_ONLY 가드).
 *
 * 사용자 결정(2026-05-24): "엑셀 예시대로". DEPOSIT 은 확정 입금 합을 양수로 기입
 * (BALANCE MONEY 는 양식 수식 그대로). Invoice No = {영업담당자 이니셜}MU{차대번호 숫자}
 * (item 7, jin 2026-07-18. 이니셜/차대번호 미입력 시 SC{연월}-{id} fallback). Name(E5)=바이어, Client(E6)=컨사이니.
 * SUB TOTAL/TOTAL/BALANCE(E27/E31/E34) 는 수식 — 엔진이 자동 보존·재계산.
 */
class SalesInvoiceMapping
{
    public static function config(): array
    {
        return [
            'template' => 'sales_invoice.xlsx',
            'sheet' => 'Invoice',
            'label' => 'Invoice',
            // 판매통화 적응 ($ → 통화기호) — 2026-06-24.
            'currencyAware' => true,
            // DEPOSIT 행 제거 — 합계(E31)/잔액(E34) 수식 범위에 양수로 들어가 더블카운트(11400) 유발.
            // E29(값)은 노란셀이라 clearYellowFill 로 자동 공란, C28/C29(라벨)은 비노란이라 강제 공란. (jin 2026-06-24)
            'clearCells' => ['C28', 'C29'],
            'cells' => [
                'E3' => fn (Vehicle $v) => $v->sale_date ?: now(),                                   // Date
                'E4' => fn (Vehicle $v) => DocValue::invoiceNo($v),                                   // Invoice No. = {이니셜}{차대번호 끝자리숫자} (item 7)
                'E5' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->name,                          // Buyer Name
                'E6' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,                      // Client Name
                'E7' => fn (Vehicle $v) => DocValue::consigneeIdValue($v),                             // Passport
                'E8' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->address ?: DocValue::invoiceBuyer($v)?->address, // Address
                'E9' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->contact_phone ?: DocValue::invoiceBuyer($v)?->contact_phone, // Phone
                'E10' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->contact_email,                // Email (바이어 이메일, A안 신규행 — jin 2026-07-21)
                'E11' => fn (Vehicle $v) => DocValue::money($v->exchange_rate),                        // Dollar Rate (Email 행 추가로 E10→E11 이동)
                'A18' => fn (Vehicle $v) => $v->vehicle_number,                                        // Code
                'B18' => fn (Vehicle $v) => $v->brand,                                                 // Maker
                'C18' => fn (Vehicle $v) => DocValue::carName($v),                                     // Model
                'D18' => fn (Vehicle $v) => $v->nice_reg_vin,                                          // Chassis No.
                'E18' => fn (Vehicle $v) => DocValue::money($v->sale_price),                           // FOB PRICE
                'F18' => fn (Vehicle $v) => DocValue::money($v->transport_fee),                        // Shipping cost
                'E24' => fn (Vehicle $v) => DocValue::money($v->commission),                           // COMMISSION
                'E25' => fn (Vehicle $v) => DocValue::money($v->auto_loading),                         // AUTO LODING
                'E26' => fn (Vehicle $v) => $v->tax_dc ? -1 * $v->tax_dc : null,                       // TAX D/C (양식 SUM 에 더해지므로 음수로 — 할인)
                // E29 DEPOSIT 제거 — TOTAL/BALANCE(=SUM(E27:F30)) 에 양수로 가산돼 판매총액 2배 버그. (jin 2026-06-24)
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
