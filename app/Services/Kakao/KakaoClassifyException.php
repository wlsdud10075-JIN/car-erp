<?php

namespace App\Services\Kakao;

use RuntimeException;

/**
 * 모델 호출은 됐는데 결과를 못 쓰는 경우(출력 잘림·JSON 아님·거부) — 토큰은 이미 썼으므로 usage 를 실어 회차에 기록하게 한다.
 * (11-11 실사고: 267건을 한 호출로 보내 16k 출력 상한에서 잘렸는데 4회 재시도가 전부 0원으로 기록됐다.)
 */
class KakaoClassifyException extends RuntimeException
{
    /** @var array{input_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int}|null */
    public ?array $usage = null;

    public ?string $model = null;
}
