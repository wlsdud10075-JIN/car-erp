<?php

namespace App\Exceptions;

/**
 * 옛 폼 저장 거부 (jin 2026-10-02) — 패널을 연 뒤 다른 사용자(또는 다른 창)가 같은 차량을 먼저 저장했다.
 *
 * 실사고 ssancarerp: 판매잔금이 같은 금액으로 2행(append-only 라 또 insert) · 매입잔금은 앞사람 행이 지워짐
 * (「폼에 없는 행 삭제」 방식) · 앞사람이 넣은 컨테이너 번호·면장번호가 빈값으로 되돌아감.
 * 판정 = Vehicle::editFingerprint() — 패널 열 때 값 ↔ 저장 트랜잭션 안(행 잠금 뒤) 값.
 * DomainException 계열이라 save() 의 기존 catch 가 토스트로 받고, 전용 catch 가 패널을 다시 연다.
 */
class StaleFormException extends \DomainException {}
