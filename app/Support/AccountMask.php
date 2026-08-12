<?php

namespace App\Support;

use App\Models\Vehicle;

/**
 * 🔒 계좌번호 마스킹 — 외부(딜러)로 나가는 문자·알림톡 전용 (jin 2026-08-12).
 *
 * 왜 마스킹하나: 알림톡 수신 번호는 **사람이 손으로 적는다**(`deregistration_notice_phone`).
 * 한 자리만 틀려도 남의 계좌번호가 통째로 낯선 사람에게 간다. 받는 분은 자기 계좌를 이미
 * 알고 있으므로 **본인 확인에 필요한 만큼**(은행 + 뒤 4자리)만 실으면 충분하다.
 *
 * ⚠️ `purchase_seller_account` 는 `encrypted` 캐스트다 — 모델을 거쳐야 평문이 나온다.
 *    쿼리로 직접 뽑아 쓰지 말 것(암호문이 그대로 나간다).
 * ⚠️ 운영 실측(heymanerp 2026-08-12): 매입차 243대 중 계좌가 채워진 건 **67대**뿐이다.
 *    없다고 발송을 막지는 않는다 — 금액·구분만으로도 통지의 목적은 달성된다.
 */
class AccountMask
{
    /** 계좌 정보가 없을 때 본문에 들어갈 값. 빈 문자열을 넘기면 알림톡 변수 자리가 어색하게 빈다. */
    public const NONE = '-';

    /** 뒤에서 몇 자리를 보여줄지. 4자리 = 은행 앱·영수증의 관례. */
    public const VISIBLE = 4;

    public static function forVehicle(Vehicle $vehicle): string
    {
        return self::format($vehicle->purchase_seller_bank, $vehicle->purchase_seller_account);
    }

    /** 예: ('국민', '123456-78-901234') → '국민 ****1234' / 계좌 없으면 '-'. */
    public static function format(?string $bank, ?string $account): string
    {
        $digits = preg_replace('/\D/', '', (string) $account) ?? '';
        $bank = trim((string) $bank);

        if ($digits === '') {
            // 은행만 있고 번호가 없으면 은행명이라도 준다 — 아무것도 없는 것보단 확인에 도움이 된다.
            return $bank !== '' ? $bank : self::NONE;
        }

        // 4자리 이하(오입력·부분 입력)면 뒤 4자리가 곧 전체다 — 통째로 가린다.
        $masked = mb_strlen($digits) > self::VISIBLE
            ? str_repeat('*', self::VISIBLE).mb_substr($digits, -self::VISIBLE)
            : str_repeat('*', self::VISIBLE);

        return trim($bank.' '.$masked);
    }
}
