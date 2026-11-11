<?php

namespace App\Services\Kakao;

use Anthropic\Client;
use RuntimeException;

/**
 * Claude API(Sonnet 5.5) 호출 — 시스템 프롬프트는 캐시(cache_control), 출력은 JSON 스키마 고정.
 * 키·모델은 config('services.anthropic.*') 에서만 읽는다(운영은 config:cache 라 env() 직접 호출 금지).
 * 표본 실측(2026-11-10): 50건 창 ≈ 2~3k 입력 토큰(캐시 2k) · 2k 출력 · 10초 · 36~48원.
 */
class AnthropicKakaoClient implements KakaoClassifierClient
{
    public function __construct(private ?Client $client = null) {}

    public function classify(string $system, string $user, array $schema): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY 가 설정되지 않았습니다(.env → config/services.php).');
        }
        $model = (string) config('services.anthropic.model', 'claude-sonnet-5-5');
        $client = $this->client ?? new Client(apiKey: $key);

        $message = $client->messages->create(
            model: $model,
            maxTokens: 16000,
            system: [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => $user]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema], 'effort' => 'medium'],
        );

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $u = $message->usage;
        $usage = [
            'input_tokens' => (int) ($u->inputTokens ?? 0),
            'cache_read_tokens' => (int) ($u->cacheReadInputTokens ?? 0),
            'cache_write_tokens' => (int) ($u->cacheCreationInputTokens ?? 0),   // 첫 호출(5분 안 재호출 없을 때) 1.25배 과금
            'output_tokens' => (int) ($u->outputTokens ?? 0),
        ];
        $stop = $message->stopReason ?? null;
        if ($stop === 'refusal') {
            throw self::failure('모델이 분류를 거부했습니다(refusal).', $usage, $model);
        }
        if ($stop === 'max_tokens') {   // 11-11 실사고 — 267건 한 호출이 16k 출력에서 잘려 JSON 이 끊겼다. 지금은 60건씩 보내므로 나면 코드 쪽 문제
            throw self::failure('모델 출력이 길이 상한에서 잘렸습니다(max_tokens) — 한 번에 보내는 건수를 줄여야 합니다.', $usage, $model);
        }
        $data = json_decode($text, true);
        if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
            throw self::failure('모델 응답이 JSON 스키마와 다릅니다: '.mb_substr($text, 0, 200), $usage, $model);
        }

        return [
            'items' => $data['items'],
            'usage' => $usage,
            'model' => (string) ($message->model ?? $model),
        ];
    }

    private static function failure(string $message, array $usage, string $model): KakaoClassifyException
    {
        $e = new KakaoClassifyException($message);
        $e->usage = $usage;
        $e->model = $model;

        return $e;
    }
}
