<?php

namespace App\Services\Kakao;

/**
 * 분류 모델 호출을 감싸는 인터페이스 — 운영은 AnthropicKakaoClient(Sonnet), 테스트는 녹음된 응답을 돌려주는 가짜.
 */
interface KakaoClassifierClient
{
    /**
     * @param  array<string, mixed>  $schema  JSON 스키마(구조화 출력)
     * @return array{items: array<int, array<string, mixed>>, usage: array{input_tokens: int, cache_read_tokens: int, output_tokens: int}, model: string}
     */
    public function classify(string $system, string $user, array $schema): array;
}
