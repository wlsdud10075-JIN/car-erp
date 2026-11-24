<?php

namespace App\Services\Assistant;

/**
 * 로컬 Ollama HTTP 클라이언트 (jin 2026-07-24).
 *   완전 로컬 — 데이터 외부 전송 0. board PoC rag.php 의 curl 로직 이식.
 *   테스트는 이 클래스를 컨테이너에서 fake 로 바인딩(HTTP 미발생).
 */
class OllamaClient
{
    public function __construct(
        private string $baseUrl,
        private int $timeout = 180,
    ) {}

    public static function fromConfig(): self
    {
        return new self((string) config('assistant.ollama'), (int) config('assistant.timeout', 180));
    }

    /** 임베딩 1건 (bge-m3). 실패 시 빈 배열. */
    public function embed(string $model, string $text): array
    {
        $r = $this->post('/api/embed', ['model' => $model, 'input' => [$text]]);

        return $r['embeddings'][0] ?? [];
    }

    /** 채팅 완성 (qwen3:8b). think=false + <think> 제거. */
    public function chat(string $model, string $system, string $user): string
    {
        $r = $this->post('/api/chat', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'think' => false,
            'stream' => false,
        ]);
        $answer = $r['message']['content'] ?? '';

        return trim(preg_replace('/<think>.*?<\/think>/su', '', $answer));
    }

    /**
     * 비전 완성 (qwen2.5vl) — 그림 1장 + 프롬프트 → 텍스트. board `OllamaClient::vision()` 과 **같은 본문**이다
     * (board OCR 이 상주시킨 모델을 다시 올리지 않게 — config assistant.remitter_ocr 주석).
     */
    public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
    {
        $r = $this->post('/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'images' => [$imageBase64],
            'stream' => false,
            'options' => ['temperature' => 0, 'num_ctx' => $numCtx],
        ]);

        return trim($r['response'] ?? '');
    }

    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl.$path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Ollama 연결 실패: {$err}");
        }
        curl_close($ch);

        return json_decode($res, true) ?? [];
    }
}
