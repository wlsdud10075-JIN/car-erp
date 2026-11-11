<?php

namespace Tests\Feature\Kakao;

use App\Services\Kakao\KakaoClassifierClient;

/**
 * 테스트용 분류기 — 받은 창 텍스트를 기록하고, 분류 대상 id 목록(첨부 제외)을 plan 콜백에 넘겨 판정을 돌려준다.
 */
class FakeClassifier implements KakaoClassifierClient
{
    public int $calls = 0;

    public string $lastSystem = '';

    public string $lastUser = '';

    public array $lastSchema = [];

    /** @var callable(array<int,int>): array */
    public $plan;

    public function classify(string $system, string $user, array $schema): array
    {
        $this->calls++;
        $this->lastSystem = $system;
        $this->lastUser = $user;
        $this->lastSchema = $schema;
        // 창에서 「이번에 분류할 메시지」의 id 를 순서대로 뽑되 (첨부)·(규칙·…) 표식 줄은 뺀다
        $section = substr($user, strpos($user, '## 이번에 분류할 메시지'));
        preg_match_all('/^id=(\d+) .*$/mu', $section, $m, PREG_SET_ORDER);
        $ids = [];
        foreach ($m as $row) {
            if (! str_contains($row[0], '(첨부:') && ! str_contains($row[0], '(규칙·')) {
                $ids[] = (int) $row[1];
            }
        }
        $items = ($this->plan)($ids);

        return [
            'items' => $items,
            'usage' => ['input_tokens' => 2500, 'cache_read_tokens' => 2000, 'cache_write_tokens' => 0, 'output_tokens' => 1500],
            'model' => 'claude-sonnet-5-5',
        ];
    }
}
