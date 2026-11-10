<?php

namespace Tests\Unit\Kakao;

use App\Services\Kakao\KakaoExportParser;
use PHPUnit\Framework\TestCase;

/**
 * 카톡 복사 텍스트 파서 — 날짜 구분선 · 오전/오후 · 여러 줄 병합 · 삭제/초대 줄 제외 · 날짜 모르는 머리줄 처리.
 * (표본 형식은 docs/design/kakao-automation.md §3. 이름은 가명.)
 */
class KakaoExportParserTest extends TestCase
{
    private const SAMPLE = <<<'TXT'
--------------- 2026년 6월 10일 수요일 ---------------
[영업A] [오전 8:38] .
[영업A] [오전 8:44] 차량명   AUDI A3
차량번호:   38누0655
차대번호   WAUZZZ8VXG1078563

바이어:   DONI
[재무A] [오후 12:07] DONI 미수 너무 많습니다 매입 제한이요
메시지가 삭제되었습니다.
[영업A] [오후 12:07] 오늘 30,000 보낸준다고 합니다
영업A님이 수출팀A님을 초대했습니다.
--------------- 2026년 6월 11일 목요일 ---------------
[재무A] [오전 12:05] 자정 넘어
[수출팀A] [오후 11:59] 사진 6장
TXT;

    public function test_parses_dates_times_and_multiline_bodies(): void
    {
        $msgs = (new KakaoExportParser)->parse(self::SAMPLE);

        $this->assertCount(6, $msgs);
        $this->assertSame('2026-06-10 08:38:00', $msgs[0]['sent_at']);
        $this->assertSame('영업A', $msgs[0]['sender']);
        $this->assertSame('.', $msgs[0]['body']);

        // 서식 메시지 = 이어지는 줄이 붙고, 빈 줄은 빠진다
        $this->assertSame("차량명   AUDI A3\n차량번호:   38누0655\n차대번호   WAUZZZ8VXG1078563\n바이어:   DONI", $msgs[1]['body']);

        // 오후 12:07 = 12:07, 오전 12:05 = 00:05, 오후 11:59 = 23:59
        $this->assertSame('2026-06-10 12:07:00', $msgs[2]['sent_at']);
        $this->assertSame('DONI 미수 너무 많습니다 매입 제한이요', $msgs[2]['body']);   // 끝 공백 제거
        $this->assertSame('2026-06-11 00:05:00', $msgs[4]['sent_at']);
        $this->assertSame('2026-06-11 23:59:00', $msgs[5]['sent_at']);
    }

    public function test_deleted_and_invite_lines_are_dropped_not_merged(): void
    {
        $msgs = (new KakaoExportParser)->parse(self::SAMPLE);

        $this->assertSame('오늘 30,000 보낸준다고 합니다', $msgs[3]['body']);
        foreach ($msgs as $m) {
            $this->assertStringNotContainsString('삭제되었습니다', $m['body']);
            $this->assertStringNotContainsString('초대했습니다', $m['body']);
        }
    }

    public function test_msg_key_is_stable_and_differs_by_time_sender_body(): void
    {
        $a = (new KakaoExportParser)->parse(self::SAMPLE);
        $b = (new KakaoExportParser)->parse(self::SAMPLE);
        $this->assertSame(array_column($a, 'msg_key'), array_column($b, 'msg_key'));
        $this->assertCount(6, array_unique(array_column($a, 'msg_key')));
        $this->assertSame(40, strlen($a[0]['msg_key']));
    }

    public function test_messages_before_first_date_divider_need_a_fallback_date(): void
    {
        $text = "[영업A] [오전 9:00] 날짜 모름\n이어지는 줄\n--------------- 2026년 6월 10일 수요일 ---------------\n[영업A] [오전 9:01] 날짜 앎";

        $withoutFallback = (new KakaoExportParser)->parse($text);
        $this->assertCount(1, $withoutFallback);
        $this->assertSame('날짜 앎', $withoutFallback[0]['body']);

        $withFallback = (new KakaoExportParser)->parse($text, '2026-06-09');
        $this->assertCount(2, $withFallback);
        $this->assertSame('2026-06-09 09:00:00', $withFallback[0]['sent_at']);
        $this->assertSame("날짜 모름\n이어지는 줄", $withFallback[0]['body']);
    }

    public function test_attachment_and_spacer_detection(): void
    {
        foreach (['.', '.1', '2', '3.', '사진', '사진 6장', '파일: 1078563.pdf', '이모티콘', '동영상', ''] as $body) {
            $this->assertTrue(KakaoExportParser::isAttachment($body), $body);
        }
        foreach (['1730', '넵', '입금 부탁드립니다', '사진 보내주세요', 'A7'] as $body) {
            $this->assertFalse(KakaoExportParser::isAttachment($body), $body);
        }
    }
}
