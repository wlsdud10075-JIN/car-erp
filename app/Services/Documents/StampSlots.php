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
        // karaba 가 이 type 에 슬롯을 갖게 되면서(2026-09-04) 설정화면에 노출된다 — 라벨이 없으면
        //   키 문자열 'deregistration_set' 이 그대로 찍힌다(admin/settings 의 `?? $type` 폴백).
        'deregistration_set' => '말소신청서_계약서',
        'invoice' => '판매 인보이스',
        'clearance' => '통관 SET',
        'container_invoice_packing' => '컨테이너 Invoice&Packing',
        'container_contract' => '컨테이너 계약서',
        'roro_invoice_packing' => 'RORO Invoice&Packing',
        'roro_contract' => 'RORO 계약서',
        'sales_contract' => '판매 계약서',
    ];

    /**
     * 회사(template_set)별 슬롯. heyman 은 jin 2026-06-25 정책(서명=말소계약서만 /
     * 직인=판매·선적인보이스·계약서·통관 차량인보이스/팩킹/Travel, 등록증·말소증 정부직인 제외).
     * karaba 는 기본 배치 + 말소신청서 탭 직인(2026-09-04 jin). 그 외(system)는 기본 배치.
     *
     * @return array<string, list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}>>
     */
    public static function all(?string $set = null): array
    {
        return match ($set) {
            'heyman' => self::heymanSlots(),
            'karaba' => self::karabaSlots(),
            default => self::defaultSlots(),
        };
    }

    /**
     * karaba 전용 (jin 2026-09-04) — 「말소신청서_계약서」의 **1.차량말소신청서 탭**에 직인을 찍는다.
     *
     * 🔑 **기본 배치를 복사하지 않고 얹는다.** 복사하면 기본 슬롯이 바뀔 때 karaba 만 조용히 뒤처진다
     *    (heymanSlots 는 전량 복사라 그 위험을 안고 있다 — 여기서 되풀이하지 않는다, SKILLS §8 #45).
     *
     * 📏 **크기 323×158px = 8.55×4.18cm — 「Proforma Invoice 와 같은 사이즈」(jin 2026-09-04).**
     *    karaba 직인은 정사각 도장이 아니라 **가로로 긴 사업자 고무인 블록**
     *    (사업자번호·상호·대표·주소·업태 7줄 + 빨간 인감, 운영 실측 1902×930 = 2.045:1)이다.
     *    `overlayStamp` 는 상자 안에서 **비율맞춤(contain)** 하므로 상자 세로가 낮으면
     *    **가로가 저절로 깎인다** — 처음 셀 크기 그대로 233×85 로 잡아 **174×85 로 찍혔고**
     *    7줄 글자가 뭉갰다. 여기 323×158 은 인보이스 상자(323×192)가 같은 도장을 찍는 크기와
     *    **동일**하다(둘 다 가로가 병목이라 323×158 로 떨어진다).
     *
     * 위치 = 신청인 블록 우측(I25). **셀 칸을 넘는다 — 의도한 것이다.**
     *    - 서명란 K~M 은 218px(5.8cm)뿐이라 8.55cm 를 담을 수 없다. 인쇄영역이 `A1:M39` 라
     *      K 에서 시작하면 M 오른쪽으로 105px 가 **잘린다** → 시작을 I 로 당겼다.
     *    - 가로 560+323 = **883 ≤ 918**(M 오른쪽 끝) — 인쇄영역 안이다.
     *    - 세로 158 은 25행 위 → 29행 굵은 구분선(148px)을 10px 넘고, 「위 임 장」 제목(32행,
     *      201px)까지는 43px 남는다. 겹치는 칸(신청인 주소·성명·주민등록번호)은 **매핑이 없어
     *      생성물에서 빈칸**이다(`DeregistrationMapping::config`).
     *    - 🔑 **배경 없는(투명) 직인 전제**다(jin 2026-09-04 «직인이 배경없는거로 바꿔서
     *      사이즈가 좀 커져도 괜찮거든»). 흰 배경 스캔본을 올리면 아래 선·글자를 가린다.
     *    - baked drawing 이 없어 `clearAnchors` 가 필요 없다(3세트 실측 drawing 0건).
     *
     * ⚠️ **찍히는 크기는 올린 이미지의 비율이 정한다** — 상자는 상한일 뿐이다. 2.045:1 보다
     *    세로로 긴 도장을 올리면 세로 158 이 병목이 되어 가로가 8.55cm 보다 줄어든다.
     * ⚠️ cm 로 말할 땐 **인쇄배율**을 곱할 것 — 이 시트는 `fitToPage`(1쪽 폭, 실측 69%)라
     *    323×158px 은 **종이에선 약 5.9×2.9cm** 다(인보이스 시트와 인쇄배율이 다르면 종이 위
     *    크기도 다르다 — 「같은 사이즈」는 엑셀 100% 기준이다).
     * ⚠️ `exact` 를 쓰지 말 것 — 상자 크기로 늘여 박아서 다른 비율의 도장을 올리면 찌그러진다.
     *    미세조정은 배포 없이 기능설정 「도장 슬롯 위치/크기」
     *    (`stamp_pos_karaba_deregistration_set_apply_seal`)로 한다.
     *
     * 🚫 `defaultSlots()` 에 직접 넣지 말 것 — system(ssancarerp)에도 찍힌다.
     */
    private static function karabaSlots(): array
    {
        $slots = self::defaultSlots();
        $slots['deregistration_set'][] = [
            'key' => 'apply_seal', 'role' => 'seal', 'sheet' => '1.차량말소신청서',
            'anchor' => 'I25', 'width' => 323, 'height' => 158,
        ];

        return $slots;
    }

    /**
     * 기본(SSANCAR/karaba) 슬롯.
     *
     * @return array<string, list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}>>
     */
    private static function defaultSlots(): array
    {
        return [
            'deregistration_contract' => [
                // 서명 — jin 지정 11.38cm×1.59cm(=430×60px) @ A62. exact=지정크기 그대로(비율맞춤 아님).
                // clearAnchors: 양식 baked 서명(A60)을 업로드본 추가 전 제거 → 이중 서명 방지.
                ['key' => 'sign', 'role' => 'signature', 'sheet' => '2.계약서', 'anchor' => 'A62', 'width' => 430, 'height' => 60, 'exact' => true, 'clearAnchors' => ['A60']],
            ],
            // item 8 (2026-07-18) — 병합본. 계약서 시트('2.계약서')는 graft 되므로 슬롯 동일.
            'deregistration_set' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => '2.계약서', 'anchor' => 'A62', 'width' => 430, 'height' => 60, 'exact' => true, 'clearAnchors' => ['A60']],
            ],
            'invoice' => [
                // 2026-07-31 다중차량 전환으로 슬롯이 30행 늘어 baked 직인이 B36 → B65 로 밀렸다.
                //   앵커가 어긋나면 removeDrawingsAt 이 원본을 못 지워 업로드 직인과 **이중 도장**이 된다(§8 #37 ③).
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'Invoice', 'anchor' => 'B65', 'width' => 323, 'height' => 192],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'Invoice', 'anchor' => 'A1', 'width' => 333, 'height' => 72],
            ],
            'container_invoice_packing' => [
                // 서명 — jin 지정 7.7cm×3.63cm(=291×137px). exact=지정크기 그대로(비율맞춤 아님).
                ['key' => 'sign', 'role' => 'signature', 'sheet' => 'INVOICE', 'anchor' => 'H115', 'width' => 291, 'height' => 137, 'exact' => true],
            ],
            'container_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            'roro_invoice_packing' => [
                // 서명 — jin 지정 7.7cm×3.63cm(=291×137px). exact=지정크기 그대로(비율맞춤 아님).
                ['key' => 'sign', 'role' => 'signature', 'sheet' => 'INVOICE', 'anchor' => 'H55', 'width' => 291, 'height' => 137, 'exact' => true],
            ],
            'roro_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            // 판매 계약서 — SELLER 서명칸 직인. 양식 baked 도장(361×203 @ C70)과 **동일 박스여야 한다**:
            //   removeDrawingsAt 이 "정확히 같은 앵커"만 지우므로, 어긋나면 baked 와 업로드본이 겹쳐
            //   **이중 도장**이 된다. 2026-07-29 새 레이아웃에서 baked 가 B71 → C70 으로 옮겨졌다.
            //   fillMulti removeRow 前 오버레이라 트림된 위치로 함께 이동(선적 계약서와 동일).
            'sales_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'CONTRACT', 'anchor' => 'C70', 'width' => 361, 'height' => 203],
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

    /**
     * HEYMAN 전용 슬롯 (jin 2026-06-25).
     * - 서명(signature): 말소계약서만.
     * - 직인(seal): 판매인보이스 / 선적인보이스(컨테이너·RORO) / 계약서(컨테이너·RORO) /
     *   통관 SET 차량인보이스·차량팩킹·Travel.
     * - 등록증·말소증: 정부 공인직인 자리 → 회사도장 미부착(슬롯 없음).
     * 직인으로 바뀐 슬롯은 기본 박스 안에서 비율맞춤(exact 미사용 → 정사각 직인 안 찌그러짐).
     *
     * @return array<string, list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}>>
     */
    private static function heymanSlots(): array
    {
        return [
            'deregistration_contract' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => '2.계약서', 'anchor' => 'A62', 'width' => 430, 'height' => 60, 'exact' => true, 'clearAnchors' => ['A60']],
            ],
            'deregistration_set' => [
                ['key' => 'sign', 'role' => 'signature', 'sheet' => '2.계약서', 'anchor' => 'A62', 'width' => 430, 'height' => 60, 'exact' => true, 'clearAnchors' => ['A60']],
            ],
            'invoice' => [
                // 2026-07-31 다중차량 전환으로 슬롯이 30행 늘어 baked 직인이 B36 → B65 로 밀렸다.
                //   앵커가 어긋나면 removeDrawingsAt 이 원본을 못 지워 업로드 직인과 **이중 도장**이 된다(§8 #37 ③).
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'Invoice', 'anchor' => 'B65', 'width' => 323, 'height' => 192],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'Invoice', 'anchor' => 'A1', 'width' => 333, 'height' => 72],
            ],
            'container_invoice_packing' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'INVOICE', 'anchor' => 'H115', 'width' => 291, 'height' => 137],
            ],
            'container_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            'roro_invoice_packing' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'INVOICE', 'anchor' => 'H55', 'width' => 291, 'height' => 137],
            ],
            'roro_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'HBB340.', 'anchor' => 'B59', 'width' => 266, 'height' => 141],
                ['key' => 'logo', 'role' => 'logo', 'sheet' => 'HBB340.', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
            // 판매 계약서 — 직인=계약서(heyman 정책). SELLER 서명칸. baked 도장과 동일 박스 유지(위 주석 참조).
            'sales_contract' => [
                ['key' => 'seal', 'role' => 'seal', 'sheet' => 'CONTRACT', 'anchor' => 'C70', 'width' => 361, 'height' => 203],
            ],
            // 등록증·말소증 정부직인은 슬롯에서 제외. 회사 직인은 차량인보이스·팩킹·Travel 만.
            'clearance' => [
                ['key' => 'seal_invoice', 'role' => 'seal', 'sheet' => '차량인보이스', 'anchor' => 'G33', 'width' => 290, 'height' => 137],
                ['key' => 'seal_packing', 'role' => 'seal', 'sheet' => '차량팩킹', 'anchor' => 'G33', 'width' => 290, 'height' => 136],
                ['key' => 'seal_travel', 'role' => 'seal', 'sheet' => 'Travel Services Invoice', 'anchor' => 'B28', 'width' => 291, 'height' => 188],
                ['key' => 'logo_travel', 'role' => 'logo', 'sheet' => 'Travel Services Invoice', 'anchor' => 'A1', 'width' => 246, 'height' => 55],
            ],
        ];
    }

    /** @return list<array{key:string, role:string, sheet:string, anchor:string, width:int, height:int}> */
    public static function for(string $type, ?string $set = null): array
    {
        return self::all($set ?? Setting::companyTemplateSet())[$type] ?? [];
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
