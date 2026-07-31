<?php

namespace Tests\Feature;

use App\Console\Commands\AssistantHealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 챗봇 호스트 감시 커맨드 검증 (2026-07-30).
 *
 * 감시 도구가 조용히 오작동하면 "죽어도 모르는" 원래 문제로 되돌아간다.
 * 특히 ① 재부팅 유예를 지키는가 ② 정말로 이상일 때 알리는가 두 가지가 핵심이다.
 */
class AssistantHealthCheckTest extends TestCase
{
    private string $indexPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indexPath = storage_path('framework/testing/index-erp-test.json');
        @mkdir(dirname($this->indexPath), 0777, true);
        $this->writeIndex([
            ['source' => 'ERP (car-erp) › 공통', 'audience' => 'staff'],
            ['source' => 'ERP (car-erp) › 재무', 'audience' => 'finance'],
        ]);

        config()->set('assistant.enabled', true);
        config()->set('assistant.ollama', 'http://127.0.0.1:11434');
        config()->set('assistant.index_path', $this->indexPath);
        config()->set('assistant.index_scope', '');

        Cache::flush();
    }

    /** 색인 파일을 쓰고 mtime 을 1시간 전으로 맞춘다(신선도 경고와 분리해서 보기 위해). */
    private function writeIndex(array $chunks): void
    {
        file_put_contents($this->indexPath, json_encode($chunks));
        touch($this->indexPath, time() - 3600);
    }

    protected function tearDown(): void
    {
        @unlink($this->indexPath);
        parent::tearDown();
    }

    private function health(): ?array
    {
        return Cache::get(AssistantHealthCheck::CACHE_KEY);
    }

    public function test_reports_no_problem_when_ollama_answers_and_index_is_fresh(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        touch($this->indexPath, time() - 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $h = $this->health();
        $this->assertSame([], $h['problems']);
        $this->assertTrue($h['ollama_ok']);
    }

    public function test_short_ollama_outage_is_ignored_because_of_reboot_grace(): void
    {
        // 사내 GPU PC 는 재부팅 후 약 1분 50초면 복구된다. 그 사이 경고를 띄우면 오탐이 된다.
        Http::fake(['*/api/tags' => Http::response(null, 500)]);
        touch($this->indexPath, time() - 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $h = $this->health();
        $this->assertFalse($h['ollama_ok']);
        $this->assertSame([], $h['problems'], '유예 시간 안의 짧은 실패는 경고하지 않아야 합니다.');
        $this->assertNotNull($h['ollama_down_since']);
    }

    public function test_sustained_ollama_outage_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(null, 500)]);
        touch($this->indexPath, time() - 3600);

        // 유예 시간보다 앞서 실패가 시작된 상태를 만든다.
        Cache::put('assistant.health.ollama_down_since', time() - (AssistantHealthCheck::OLLAMA_GRACE_SECONDS + 120), 86400);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $problems = $this->health()['problems'];
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Ollama', $problems[0]);
    }

    public function test_recovery_clears_the_outage_timer(): void
    {
        Cache::put('assistant.health.ollama_down_since', time() - 9999, 86400);
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        touch($this->indexPath, time() - 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertNull(Cache::get('assistant.health.ollama_down_since'),
            '복구되면 실패 타이머를 지워야 다음 장애를 처음부터 셉니다.');
        $this->assertSame([], $this->health()['problems']);
    }

    public function test_stale_index_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        touch($this->indexPath, time() - (AssistantHealthCheck::INDEX_STALE_HOURS + 2) * 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $problems = $this->health()['problems'];
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('색인', $problems[0]);
    }

    public function test_missing_index_file_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        unlink($this->indexPath);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertStringContainsString('색인 파일이 없음', $this->health()['problems'][0]);
    }

    public function test_result_expires_so_a_dead_scheduler_becomes_visible(): void
    {
        /*
         * 🚨 이 테스트가 지키는 것: Cache::forever 로 바꾸면 스케줄러가 죽어도
         * 마지막 정상 상태가 영원히 남아 화면이 계속 초록으로 보인다.
         * TTL 이 있어야 "감시 미작동" 으로 분기한다.
         */
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        touch($this->indexPath, time() - 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();
        $this->assertNotNull($this->health());

        $this->travel(AssistantHealthCheck::CACHE_TTL + 60)->seconds();
        $this->assertNull($this->health(), '결과에 TTL 이 없으면 스케줄러 정지를 감지할 수 없습니다.');
    }

    public function test_skips_entirely_when_assistant_infra_is_absent(): void
    {
        config()->set('assistant.enabled', false);
        Http::fake();

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertNull($this->health());
        Http::assertNothingSent();
    }

    // ── 색인 등급 표기 무결성 (2026-07-31 추가) ──────────────
    // 색인 세션이 매번 수동으로 보던 "청크 수 == audience 수" 를 자동화한 것.
    // 전량 미표기 = 전 직원이 대표 자료를 검색 / 부분 표기 = 미표기 청크가 통째로 사라짐. 둘 다 무증상이다.

    public function test_fully_tagged_index_passes(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertSame([], $this->health()['problems']);
    }

    public function test_completely_untagged_index_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        $this->writeIndex([['source' => 'a'], ['source' => 'b']]);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $problems = $this->health()['problems'];
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('전부 등급 미표기', $problems[0]);
    }

    public function test_partially_tagged_index_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        $this->writeIndex([
            ['source' => 'a', 'audience' => 'staff'],
            ['source' => 'b'],
        ]);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertStringContainsString('1/2', $this->health()['problems'][0]);
    }

    public function test_unknown_audience_value_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        $this->writeIndex([['source' => 'a', 'audience' => 'ceo']]);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertStringContainsString('알 수 없는 등급', $this->health()['problems'][0]);
    }

    public function test_scope_filter_matches_what_the_chatbot_actually_uses(): void
    {
        // guide() 는 스코프를 먼저 걸러낸다. board 청크가 미표기여도 ERP 챗봇에는 영향이 없어야 한다.
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        config()->set('assistant.index_scope', 'ERP (car-erp)');
        $this->writeIndex([
            ['source' => 'ERP (car-erp) › 공통', 'audience' => 'staff'],
            ['source' => '매입보드 (BOARD) › 영업'],
        ]);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertSame([], $this->health()['problems'], '스코프 밖 청크는 판정에 넣지 않아야 합니다.');
    }

    public function test_index_is_not_reparsed_when_mtime_is_unchanged(): void
    {
        // 2.6MB 파싱이라 매 5분 돌리면 낭비다. mtime 이 같으면 캐시된 판정을 재사용해야 한다.
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        $this->writeIndex([['source' => 'a']]);
        $mtime = filemtime($this->indexPath);

        $this->artisan('assistant:health-check')->assertSuccessful();
        $this->assertStringContainsString('전부 등급 미표기', $this->health()['problems'][0]);

        // 내용을 고쳐도 mtime 이 그대로면 재검사하지 않는다(캐시 적중 확인).
        file_put_contents($this->indexPath, json_encode([['source' => 'a', 'audience' => 'staff']]));
        touch($this->indexPath, $mtime);

        $this->artisan('assistant:health-check')->assertSuccessful();
        $this->assertStringContainsString('전부 등급 미표기', $this->health()['problems'][0]);
    }

    public function test_corrupt_index_is_reported(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
        file_put_contents($this->indexPath, '{not json');
        touch($this->indexPath, time() - 3600);

        $this->artisan('assistant:health-check')->assertSuccessful();

        $this->assertStringContainsString('JSON 손상', $this->health()['problems'][0]);
    }
}
