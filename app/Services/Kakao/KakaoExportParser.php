<?php

namespace App\Services\Kakao;

/**
 * 카톡 PC 창에서 Ctrl+A·C 로 복사한 텍스트(= 「대화 내용 내보내기」와 같은 형식)를 메시지 배열로.
 *
 * 형식 (docs/design/kakao-automation.md §3 실측):
 *   --------------- 2026년 6월 10일 수요일 ---------------      ← 날짜 구분선
 *   [이름] [오전 8:41] 본문                                      ← 머리줄
 *   이어지는 줄                                                   ← 머리줄이 아닌 줄은 직전 메시지에 붙는다(서식 메시지는 여러 줄)
 *   메시지가 삭제되었습니다. / ○○님이 ○○님을 초대했습니다.       ← 버린다
 *
 * 날짜 구분선이 나오기 전의 메시지는 날짜를 모른다 → $fallbackDate 가 있으면 그 날짜, 없으면 버린다
 * (틀린 날짜는 msg_key 를 바꿔 같은 메시지가 두 번 들어오게 하므로 추측하지 않는다).
 */
class KakaoExportParser
{
    private const HEAD = '/^\[(.+?)\] \[(오전|오후) (\d{1,2}):(\d{2})\] (.*)$/u';

    private const DATE = '/^-+ (\d{4})년 (\d{1,2})월 (\d{1,2})일 .요일 -+$/u';

    private const DROP = [
        '/^메시지가 삭제되었습니다\.$/u',
        '/님이 .+님을 초대했습니다\.$/u',
        '/님이 나갔습니다\.$/u',
    ];

    /**
     * @return array<int, array{sent_at: string, sender: string, body: string, msg_key: string}>
     */
    public function parse(string $text, ?string $fallbackDate = null): array
    {
        $messages = [];
        $date = $fallbackDate;
        $pendingWithoutDate = false;

        foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
            $line = rtrim($raw);

            if (preg_match(self::DATE, $line, $m)) {
                $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);

                continue;
            }

            if (preg_match(self::HEAD, $line, $m)) {
                if ($date === null) {
                    $pendingWithoutDate = true;   // 날짜를 모르는 머리줄 — 이어지는 줄도 같이 버린다

                    continue;
                }
                $pendingWithoutDate = false;
                $hour = ((int) $m[3]) % 12 + ($m[2] === '오후' ? 12 : 0);
                $messages[] = [
                    'sent_at' => sprintf('%s %02d:%s:00', $date, $hour, $m[4]),
                    'sender' => trim($m[1]),
                    'body' => $m[5],
                ];

                continue;
            }

            if ($line === '' || $pendingWithoutDate || $messages === []) {
                continue;
            }
            foreach (self::DROP as $pattern) {
                if (preg_match($pattern, $line)) {
                    continue 2;
                }
            }
            $messages[array_key_last($messages)]['body'] .= "\n".$line;   // 이어지는 줄
        }

        // msg_key = 시각|보낸이|첫 줄|같은 (시각·보낸이·첫 줄) 묶음 안 순번.
        //  - 본문 전체를 키에 넣지 않는다: Ctrl+A·C 는 화면에 풀린 만큼만 잡혀서 긴 서식 메시지의 이어지는 줄이
        //    다음 읽기에 더 붙을 수 있다 → 같은 메시지가 두 번 들어와 두 번 분류·과금된다. 첫 줄만 쓰면 키가 같고,
        //    적재 쪽(KakaoIngestService)이 더 긴 본문을 「갱신」으로 받는다.
        //  - 순번: 카톡 시각은 분 단위라 같은 사람이 같은 분에 「사진」을 두 번 보내면 키가 겹친다(표본 1 #36·37).
        $seen = [];
        foreach ($messages as &$msg) {
            $msg['body'] = trim($msg['body']);
            $group = $msg['sent_at'].'|'.$msg['sender'].'|'.self::firstLine($msg['body']);
            $ordinal = $seen[$group] = ($seen[$group] ?? -1) + 1;
            $msg['msg_key'] = self::key($msg['sent_at'], $msg['sender'], $msg['body'], $ordinal);
        }

        return $messages;
    }

    public static function key(string $sentAt, string $sender, string $body, int $ordinal = 0): string
    {
        return sha1($sentAt.'|'.$sender.'|'.self::firstLine($body).'|'.$ordinal);
    }

    public static function firstLine(string $body): string
    {
        return trim(strtok(trim($body), "\n") ?: '');
    }

    /**
     * 사진·파일·이모티콘, 그리고 사진 번호 매기기용 스페이서(「.」「1」「2.」). 분류 없이 「첨부」로 둘 수 있는 것.
     */
    public static function isAttachment(string $body): bool
    {
        $b = trim($body);

        return $b === ''
            || preg_match('/^\.?\d{0,2}\.?$/u', $b) === 1
            || preg_match('/^(사진( \d+장)?|동영상|이모티콘|음성메시지|파일: .+)$/u', $b) === 1;
    }
}
