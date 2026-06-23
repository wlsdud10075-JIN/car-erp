<?php

namespace App\Services\Documents;

use App\Models\Setting;

/**
 * 도장/서명/로고 슬롯 단일 출처 — 서류 type 별 배치 슬롯 정의.
 *
 * 각 슬롯: key(서류 내 유일)·role(seal|signature|logo)·sheet(시트명)·anchor(앵커 셀)·
 *          width·height(기본 px — 실측 양식 도장 크기).
 *
 * 기능설정에서 회사(template_set)별 role 이미지(`stamp_{set}_{role}`)를 업로드하면
 * 모든 슬롯에 재사용 + 슬롯별 위치/크기 override(`stamp_pos_{set}_{type}_{key}` = {dx,dy,w,h})로 미세조정.
 * DocumentFiller::applyStamps 와 admin/settings 가 공유.
 */
class StampSlots
{
    public const ROLE_LABELS = [
        'seal' => '직인',
        'signature' => '서명',
        'logo' => '로고',
    ];

    /** 서류 type → 한글 라벨 (UI). */
    public const DOC_LABELS = [
        'deregistration_contract' => '말소 계약서',
        'invoice' => '판매 인보이스',
        'clearance' => '통관 SET',
        'container_invoice_packing' => '컨테이너 Invoice&Packing',
        'container_contract' => '컨테이너 계약서',
        'roro_invoice_packing' => 'RORO Invoice&Packing',
        'roro_contract' => 'RORO 계약서',
    ];

    /**
     * @return array<string, list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}>>
     */
    public static function all(): array
    {
        return [
            'deregistration_contract' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => '2.계약서', 'anchor' => 'A60', 'width' => 305, 'height' => 77],
            ],
            'invoice' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'Invoice', 'anchor' => 'B36', 'width' => 323, 'height' => 192],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'Invoice', 'anchor' => 'A1', 'width' => 333, 'height' => 72],
            ],
            'container_invoice_packing' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => 'INVOICE', 'anchor' => 'H115', 'width' => 290, 'height' => 137],
            ],
            'container_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            'roro_invoice_packing' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => 'INVOICE', 'anchor' => 'H55', 'width' => 290, 'height' => 137],
            ],
            'roro_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            // ⚠ 한글/영문등록증·말소증의 빨간 직인은 "대한민국(시장·도지사) 공인 직인" = 정부 인장.
            //   회사 도장으로 덮으면 안 됨 → 슬롯에서 제외. 회사 도장/서명은 인보이스·팩킹·Travel 만.
            'clearance' => [
                ['key' => 'sign_invoice', 'role' => 'signature', 'sheet' => '차량인보이스', 'anchor' => 'G33', 'width' => 290, 'height' => 137],
                ['key' => 'sign_packing', 'role' => 'signature', 'sheet' => '차량팩킹', 'anchor' => 'G33', 'width' => 290, 'height' => 136],
                ['key' => 'sign_travel', 'role' => 'signature', 'sheet' => 'Travel Services Invoice', 'anchor' => 'B28', 'width' => 291, 'height' => 188],
                ['key' => 'logo_travel', 'role' => 'logo', 'sheet' => 'Travel Services Invoice', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
        ];
    }

    /** @return list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}> */
    public static function for(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /** 회사(set)·서류(type)·슬롯(key) 별 위치/크기 override. 미설정 시 슬롯 기본값. */
    public static function position(string $set, string $type, array $slot): array
    {
        $json = Setting::get("stamp_pos_{$set}_{$type}_{$slot['key']}");
        $o = is_string($json) && $json !== '' ? (json_decode($json, true) ?: []) : [];

        return [
            'dx' => (int) ($o['dx'] ?? 0),
            'dy' => (int) ($o['dy'] ?? 0),
            'w' => (int) ($o['w'] ?? $slot['width']),
            'h' => (int) ($o['h'] ?? $slot['height']),
        ];
    }
}
