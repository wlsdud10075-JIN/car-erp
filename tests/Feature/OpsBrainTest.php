<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ops\BrainCatalog;
use App\Services\Ops\OpsBrain;
use App\Services\Ops\OpsSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🛰️ 관제센터 3단계 세컨드 브레인 (jin 11-12) — 개발 PC 에서 목록을 만들어(BrainCatalog) 서명 POST 로 올리고(OpsBrain::store), 화면이 덩어리·목록·본문을 보여 준다.
 */
class OpsBrainTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ops-test-secret';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config(['services.ops.hmac_secret' => self::SECRET, 'services.ops.owner_emails' => ['jin@example.com'], 'services.ops.sites' => '[]']);
        $this->tmp = sys_get_temp_dir().'/brain-'.Str::random(6);
        File::makeDirectory($this->tmp.'/docs/design', 0777, true);
        File::makeDirectory($this->tmp.'/docs/operations', 0777, true);
        File::makeDirectory($this->tmp.'/tests/Feature', 0777, true);
        File::makeDirectory($this->tmp.'/memory', 0777, true);
        file_put_contents($this->tmp.'/SKILLS.md', "# SKILLS\n\n## 8. 버그\n\n### 136. 🤖 **PC 자동화는 키가 어디로 가는지** (2026-11-10)\n\n본문 136\n\n### 137. 🧩 **분류 제보 셋** (2026-11-11)\n\n본문 137 둘째 줄\n\n## 9. 패턴\n\n### 상태기반 조회\n무관\n");
        file_put_contents($this->tmp.'/docs/design/kakao.md', "# 카톡자동화 기획\n\n내용");
        file_put_contents($this->tmp.'/docs/operations/big.md', "# 큰 기록\n".str_repeat('가', BrainCatalog::BODY_MAX + 100));
        file_put_contents($this->tmp.'/tests/Feature/FooTest.php', "<?php\n/**\n * 무엇을 막는가 한 줄.\n */\nclass FooTest {}\n");
        file_put_contents($this->tmp.'/memory/project_x.md', "---\nname: project_x\ndescription: 프로젝트 X 요약\nmetadata:\n  type: project\n---\n\n본문 메모");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_catalog_reads_lessons_docs_tests_and_memory(): void
    {
        $c = BrainCatalog::build($this->tmp, $this->tmp.'/memory')['clusters'];

        $this->assertSame(['#137', '#136'], array_column($c['skills']['items'], 'no'), '최신 번호가 먼저');
        $this->assertSame('🧩 분류 제보 셋 (2026-11-11)', $c['skills']['items'][0]['title']);
        $this->assertSame('2026-11-11', $c['skills']['items'][0]['meta']);
        $this->assertStringContainsString('본문 137 둘째 줄', $c['skills']['items'][0]['body']);
        $this->assertStringNotContainsString('무관', $c['skills']['items'][0]['body'], '§9 로 넘어가면 교훈이 끝난다');
        $this->assertSame('kakao.md — 카톡자동화 기획', $c['design']['items'][0]['title']);
        $this->assertStringEndsWith('(여기서 잘림 — 전문은 레포 파일)', $c['ops']['items'][0]['body']);
        $this->assertSame('FooTest — 무엇을 막는가 한 줄.', $c['tests']['items'][0]['title']);
        $this->assertSame('', $c['tests']['items'][0]['body'], '테스트는 제목만');
        $this->assertSame(['project', '프로젝트 X 요약'], [$c['memory']['items'][0]['no'], $c['memory']['items'][0]['title']]);
        $this->assertSame(0, $c['meet']['count']);
    }

    private function signedPost(string $body, array $extra = []): TestResponse
    {
        $path = '/api/internal/ops/brain';
        $h = OpsSignature::headers('POST', $path, [], $body, self::SECRET);   // v2 — nonce 가 서명 안

        return $this->call('POST', $path, [], [], [], array_merge([
            'HTTP_X_TIMESTAMP' => $h['X-Timestamp'], 'HTTP_X_NONCE' => $h['X-Nonce'], 'HTTP_X_BOARD_SIGNATURE' => $h['X-Board-Signature'], 'CONTENT_TYPE' => 'application/gzip', 'HTTP_ACCEPT' => 'application/json',
        ], $extra), $body);
    }

    public function test_upload_needs_signature_and_accepts_gzip(): void
    {
        $json = json_encode(BrainCatalog::build($this->tmp, $this->tmp.'/memory'), JSON_UNESCAPED_UNICODE);
        $gz = gzencode($json);

        $this->call('POST', '/api/internal/ops/brain', [], [], [], ['CONTENT_TYPE' => 'application/gzip'], $gz)->assertStatus(401);
        $this->signedPost($gz)->assertOk()->assertJson(['clusters' => 7]);
        $this->assertTrue(Storage::disk('local')->exists(OpsBrain::FILE));
        $this->assertSame('#137', OpsBrain::index()['clusters']['skills']['items'][0]['no']);
        $this->assertArrayNotHasKey('body', OpsBrain::index()['clusters']['skills']['items'][0], 'index 는 본문을 뺀다');
        $this->assertStringContainsString('본문 137', OpsBrain::body('skills', '#137')['body']);

        $this->signedPost(gzencode('{"nope":1}'))->assertStatus(422);
    }

    public function test_sync_command_pushes_with_a_signature_the_receiver_accepts(): void
    {
        $sent = null;
        Http::fake(function ($req) use (&$sent) {
            $sent = $req;

            return Http::response(['clusters' => 7, 'items' => 1, 'bytes' => 1]);
        });
        $this->artisan('ops:brain-sync', ['--push' => 'https://hub.test', '--memory' => $this->tmp.'/memory'])->assertSuccessful();

        $this->assertNotNull($sent);
        $this->assertSame('https://hub.test/api/internal/ops/brain', $sent->url());
        $headers = ['HTTP_X_TIMESTAMP' => $sent->header('X-Timestamp')[0], 'HTTP_X_NONCE' => $sent->header('X-Nonce')[0], 'HTTP_X_BOARD_SIGNATURE' => $sent->header('X-Board-Signature')[0]];
        $this->signedPost($sent->body(), $headers)->assertOk();   // 보낸 쪽 서명 그대로 받는 쪽이 통과
    }

    public function test_page_shows_the_brain_and_opens_a_body(): void
    {
        OpsBrain::store(json_encode(BrainCatalog::build($this->tmp, $this->tmp.'/memory'), JSON_UNESCAPED_UNICODE));
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);

        $c = Volt::actingAs($jin)->test('admin.control');
        $html = $c->html();
        $this->assertStringContainsString('data-ops-brain', $html);
        $this->assertStringContainsString('분류 제보 셋', $html);
        $this->assertStringContainsString('프로젝트 X 요약', $html);

        $c->call('openDoc', 'skills', '#137');
        $this->assertStringContainsString('본문 137 둘째 줄', $c->html());
        $this->assertStringContainsString('data-ops-doc', $c->html());
        $c->call('closeDoc');
        $this->assertStringNotContainsString('data-ops-doc', $c->html());

        $other = User::factory()->create(['permission' => 'super', 'email' => 'other@example.com', 'email_verified_at' => now()]);
        $this->actingAs($other)->get('/admin/control')->assertNotFound();
    }

    public function test_page_without_brain_file_says_how_to_fill_it(): void
    {
        $jin = User::factory()->create(['permission' => 'super', 'email' => 'jin@example.com', 'email_verified_at' => now()]);
        $this->assertStringContainsString('ops:brain-sync', Volt::actingAs($jin)->test('admin.control')->html());
    }
}
