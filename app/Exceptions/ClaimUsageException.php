<?php

namespace App\Exceptions;

/**
 * 클레임 사용을 저장할 수 없다 (jin 2026-11-20) — 잔액 초과·미수 초과·통화 다름·바이어 없음.
 * DomainException 계열이라 차량 패널 save() 의 기존 catch 가 토스트로 받는다(트랜잭션은 되돌아간다).
 */
class ClaimUsageException extends \DomainException {}
