<?php

namespace Tests\Unit\Kakao;

use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Services\Kakao\KakaoBuckets;
use App\Services\Kakao\KakaoDirection as D;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 묶음 판정(순수 함수) — 방향 × 상태 × 시각 × 답 유무. 임계값 기본 = 지금 24h · 놓침 3일.
 */
class KakaoBucketsTest extends TestCase
{
    use RefreshDatabase;   // 임계값을 Setting(DB)에서 읽는다

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-10 14:00:00');
    }

    private function msg(string $body, ?string $type = '요청', string $sentAt = '2026-11-10 13:00:00'): KakaoMessage
    {
        $m = new KakaoMessage(['body' => $body, 'type' => $type, 'sent_at' => $sentAt]);
        $m->id = 1;

        return $m;
    }

    private function item(array $attrs): KakaoItem
    {
        return new KakaoItem($attrs);
    }

    private function bucket(string $dir, KakaoMessage $m, ?KakaoItem $item = null, bool $reply = false): ?string
    {
        return KakaoBuckets::of($dir, $m, $item, $reply, $this->now)['bucket'];
    }

    public function test_mine_splits_into_now_today_missed(): void
    {
        $this->assertSame('today', $this->bucket(D::MINE, $this->msg('명함 올려주세요')));
        $this->assertSame('now', $this->bucket(D::MINE, $this->msg('입금 부탁드립니다')));           // 돈 키워드
        $this->assertSame('now', $this->bucket(D::MINE, $this->msg('명함 올려주세요', '요청', '2026-11-09 10:00:00')));   // 24h 지남
        $this->assertSame('missed', $this->bucket(D::MINE, $this->msg('명함 올려주세요', '요청', '2026-11-06 10:00:00'))); // 3일 지남
        $this->assertSame('「입금」', KakaoBuckets::of(D::MINE, $this->msg('입금 부탁'), null, false, $this->now)['reason']);
    }

    public function test_waiting_and_replies(): void
    {
        $this->assertSame('waiting', $this->bucket(D::WAITING, $this->msg('서렌더 요청드립니다')));
        $r = KakaoBuckets::of(D::WAITING, $this->msg('서렌더 요청드립니다'), null, true, $this->now);
        $this->assertSame('done', $r['bucket']);
        $this->assertTrue($r['auto']);                                                    // 자동 완료 후보
        $this->assertSame('done', $this->bucket(D::MINE, $this->msg('확인 부탁'), null, true));   // 동료가 이미 답함
    }

    public function test_user_states_override_direction(): void
    {
        $m = $this->msg('입금 부탁');
        $this->assertNull($this->bucket(D::MINE, $m, $this->item(['state' => 'ignored'])));
        $this->assertSame('none', $this->bucket(D::MINE, $m, $this->item(['state' => 'not_mine'])));
        $this->assertSame('done', $this->bucket(D::MINE, $m, $this->item(['state' => 'done', 'done_at' => '2026-11-10 09:00:00'])));
        $this->assertNull($this->bucket(D::MINE, $m, $this->item(['state' => 'done', 'done_at' => '2026-11-09 09:00:00'])));   // 어제 완료는 안 보임
        // 미룸: 시각 전 → 미뤄둔 일, 지남 → 놓쳤을 가능성, 조건형(시각 없음) → 미뤄둔 일
        $this->assertSame('snoozed', $this->bucket(D::MINE, $m, $this->item(['state' => 'snoozed', 'snooze_until' => '2026-11-10 16:00:00'])));
        $this->assertSame('missed', $this->bucket(D::MINE, $m, $this->item(['state' => 'snoozed', 'snooze_until' => '2026-11-10 10:00:00'])));
        $this->assertSame('snoozed', $this->bucket(D::MINE, $m, $this->item(['state' => 'snoozed', 'snooze_reason' => '상대가 답하면'])));
        // 분류 수정이 유형을 바꾸면 그걸로 판정 (요청 → 잡담: 목록에서 빠짐)
        $this->assertNull($this->bucket(D::NONE, $m, $this->item(['state' => 'todo', 'type_override' => '잡담'])));
    }

    public function test_review_teammate_none_and_hidden(): void
    {
        $this->assertSame('review', $this->bucket(D::NONE, $this->msg('뜻 모를 말', null)));
        $this->assertNull($this->bucket(D::NONE, $this->msg('사진', '첨부')));
        $this->assertSame('teammate', $this->bucket(D::TEAMMATE, $this->msg('OBL 부탁')));
        $this->assertNull($this->bucket(D::TEAMMATE, $this->msg('OBL 부탁'), null, true));
        $this->assertSame('none', $this->bucket(D::NONE, $this->msg('남한테 부탁')));             // 남들끼리의 요청
        $this->assertNull($this->bucket(D::NONE, $this->msg('넵', '확인')));
    }

    public function test_order_and_labels_cover_every_bucket(): void
    {
        $this->assertSame(array_keys(KakaoBuckets::LABELS), KakaoBuckets::ORDER);
        $this->assertSame(array_keys(KakaoBuckets::HINTS), KakaoBuckets::ORDER);
    }
}
