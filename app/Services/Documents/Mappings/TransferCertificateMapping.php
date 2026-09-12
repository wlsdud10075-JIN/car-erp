<?php

namespace App\Services\Documents\Mappings;

use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 매입 — 자동차양도증명서(자동차매매업자거래용), 별지 제16호서식. jin 2026-09-11 사양 확정.
 *
 * 🧭 **방향 = 회사가 「양수인(을)」이다** — 우리가 매입하는 서류다.
 *    양도인(갑) = 차주(NICE 등록원부 소유자) / 양수인(을) = 회사.
 *    jin 이 준 기입 예시 PDF 는 **반대**(싼카가 양도인)이므로 그걸 보고 방향을 뒤집지 말 것.
 *
 * 양식 = `scripts/build-transfer-certificate-template.php` 가 **백지에서 3사를 직접 생성**한다.
 *    🚫 `generate-karaba-templates.php` 를 거치지 말 것 — 그건 회사정보 셀만 치환해서
 *       안 적힌 칸에 싼카 값이 남는다(SKILLS §8 #75, karaba 법인등록번호 실사고).
 *
 * 🟡 **좌표의 단일 출처는 아래 `CELLS` 다.** 빌더가 이 상수를 읽어 그 칸만 노란색으로 칠하므로
 *    매핑과 양식이 구조적으로 어긋날 수 없다. 좌표를 옮기려면 여기만 고친다.
 *    ⚠️ 노란칸 = `DocumentFiller::clearYellowFill` 이 기입 **전에 값을 비우는** 칸이다.
 *       회사정보·고정 리터럴(`0원정` 등)을 노란칸에 두면 서류에 **공란**이 인쇄된다(SKILLS §8 #71).
 *
 * 🚫 **RRN 을 요구하지 말 것** — 별지 제16호서식에 주민번호 칸이 **없다**(빈 양식 실측).
 *    코드 주석에 남은 「양도증명서에 RRN 필수」는 파이썬 시절 가정이다.
 *
 * 🚫 **압류·저당 칸은 비운다**(jin 2026-09-12). 원부조회(`CarmodooService`)는 모달에 띄우기만 하고
 *    DB 에 안 남는다 — 저장된 건 `has_mortgage` 체크 하나뿐이고 압류는 아예 없다.
 *    「0」을 찍으면 **깨끗하다고 자신 있게 거짓말하는** 서류가 된다. 사람이 원부를 보고 손으로 적는다.
 */
class TransferCertificateMapping
{
    /**
     * ERP 값이 들어가는 칸(= 노란칸)의 좌표. 빌더가 이 값들을 읽어 칠한다.
     *
     * 🚫 여기 없는 칸을 `cells` 에 추가하지 말 것 — 노란칸이 아니면 fill 이 안 지워져
     *    **인쇄물에 노란 배경이 그대로 남는다**. 반대로 여기 넣고 `cells` 에서 빼면
     *    그 칸은 기입 전에 비워지기만 하고 아무도 안 채운다.
     */
    public const CELLS = [
        'owner_name' => 'L5',      // 양도인(갑) 성명(명칭)
        'owner_addr' => 'L7',      // 양도인(갑) 주소
        'plate' => 'F13',          // 자동차등록번호
        'mileage' => 'Y13',        // 주행거리
        'form_year' => 'F14',      // 차종 (차종명 + 연식)
        'car_name' => 'Y14',       // 차명
        'vin' => 'F15',            // 차대번호
        'down_date' => 'Y15',      // 계약금 지급일
        'down_amount' => 'AE15',   // 계약금
        'balance_date' => 'Y16',   // 잔금 지급일
        'balance_amount' => 'AE16', // 잔금
        'price' => 'F17',          // 매매금액
    ];

    public static function config(): array
    {
        $c = self::CELLS;

        return [
            'template' => 'transfer_certificate.xlsx',
            'sheet' => '3.양도증명서',
            'label' => '양도증명서',
            'cells' => [
                // ── 계약당사자 — 갑(양도인)만 ERP 값. 을(양수인)은 회사라 양식 셀에 박혀 있다.
                $c['owner_name'] => fn (Vehicle $v) => $v->nice_reg_owner_name,
                $c['owner_addr'] => fn (Vehicle $v) => $v->nice_reg_owner_addr,

                // ── 중고자동차 매매계약서
                $c['plate'] => fn (Vehicle $v) => $v->vehicle_number,
                $c['mileage'] => fn (Vehicle $v) => ($v->mileage ?? 0) > 0 ? (int) $v->mileage : null,
                $c['form_year'] => fn (Vehicle $v) => self::formAndYear($v),
                $c['car_name'] => fn (Vehicle $v) => DocValue::carName($v) ?: null,
                $c['vin'] => fn (Vehicle $v) => $v->nice_reg_vin,

                // 계약금·잔금 = **확정된** 매입 잔금 행만(`confirmed_at` 있는 것). 미수·정산과 같은 기준(SKILLS §13).
                // 날짜는 그 종류의 마지막 지급일 — 2~3회 분할 지급이 실재한다(SKILLS §54-B ①).
                $c['down_date'] => fn (Vehicle $v) => self::paidDate($v, 'down'),
                $c['down_amount'] => fn (Vehicle $v) => self::paidSum($v, 'down'),
                $c['balance_date'] => fn (Vehicle $v) => self::paidDate($v, 'balance'),
                $c['balance_amount'] => fn (Vehicle $v) => self::paidSum($v, 'balance'),

                // 매매금액 = 매입가. 0 이면 빈칸이 맞다 — 매입가 없는 차에 「일금 0원정」을 찍지 않는다.
                $c['price'] => fn (Vehicle $v) => ($v->purchase_price ?? 0) > 0 ? (int) $v->purchase_price : null,
            ],
        ];
    }

    /** NICE 차종의 종류 부분. `DocValue::vehicleFormEn` 의 `$kinds` 와 같은 목록이다. */
    private const KINDS = ['승용', '승합', '화물', '특수'];

    /**
     * 「차종」 칸 = 종류 + 연식 (예 `승용 2022`). jin 2026-09-12 — 기입 예시 실물이 그 형태다.
     *
     * 🚨 **첫 토큰을 자르면 안 된다.** NICE 값은 크기까지 들어오는데 **순서와 띄어쓰기가 섞여 있다** —
     *    `승용 중형` · `중형 승용` · `중형승용` 이 운영에 공존한다(SKILLS §8 #75-C, 옛 적재분 표기).
     *    첫 토큰을 쓰면 `중형 승용` 인 차가 「중형 2022」로 인쇄된다. 예외도 로그도 없다.
     *    ⇒ `DocValue::vehicleFormEn` 과 같은 방식으로 **포함 여부**로 찾는다.
     *
     * 아는 종류가 없으면 원본을 그대로 통과시킨다 — 영문 변환과 같은 원칙이다(모르는 값을 지어내지 않는다).
     * 차종·연식이 둘 다 없으면 null(빈칸). 없는 값을 만들어 채우지 않는다(SKILLS §8 #71).
     */
    public static function formAndYear(Vehicle $v): ?string
    {
        $form = trim((string) $v->nice_reg_vehicle_form);
        $kind = '';
        foreach (self::KINDS as $k) {
            if ($form !== '' && str_contains($form, $k)) {
                $kind = $k;
                break;
            }
        }
        if ($kind === '') {
            $kind = $form;   // 모르는 표기는 원본 그대로 — 위장하지 않는다
        }

        $year = (int) ($v->year ?? 0);
        $out = trim($kind.' '.($year > 0 ? (string) $year : ''));

        return $out === '' ? null : $out;
    }

    /** 확정된 매입 잔금 합계(원). 없으면 null — 「일금 0원정」이 아니라 빈칸이다. */
    private static function paidSum(Vehicle $v, string $type): ?int
    {
        $sum = (int) self::confirmed($v, $type)->sum('amount');

        return $sum > 0 ? $sum : null;
    }

    /** 그 종류의 마지막 지급일. 분할 지급이면 마지막 건이 「그 돈이 다 들어온 날」이다. */
    private static function paidDate(Vehicle $v, string $type): ?\DateTimeInterface
    {
        return self::confirmed($v, $type)
            ->filter(fn ($p) => $p->payment_date !== null)
            ->sortBy('payment_date')
            ->last()?->payment_date;
    }

    private static function confirmed(Vehicle $v, string $type)
    {
        return $v->purchaseBalancePayments
            ->filter(fn (PurchaseBalancePayment $p) => $p->type === $type && $p->confirmed_at !== null);
    }
}
