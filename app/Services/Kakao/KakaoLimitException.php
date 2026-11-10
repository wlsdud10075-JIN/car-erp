<?php

namespace App\Services\Kakao;

use RuntimeException;

/** 읽기 상한(새 메시지 수·하루 비용)에 걸렸을 때 — 수집기에 그대로 보여 줄 한국어 메시지. */
class KakaoLimitException extends RuntimeException {}
