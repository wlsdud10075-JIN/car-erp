<?php

namespace App\Console\Commands;

use App\Models\KakaoMessage;
use App\Services\Kakao\KakaoExportParser;
use App\Services\Kakao\KakaoIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 11-11 파서 버그 뒤처리 — 본문 없는 머리줄(「[이다은] [오후 3:22]」로 끝나는 줄)이 직전 메시지에 붙어 한 덩어리가 된 행을 다시 나눈다.
 * (실측 ssancarerp 4행 · heymanerp 1행 — 「사진 5장 ⏎ [이다은] [오후 3:22] ⏎ 차량명…」처럼 사진이 요청으로 둔갑.)
 *
 * 나누는 법 = 그 행의 본문을 그 행의 날짜를 기준으로 파서에 다시 넣는다. 첫 조각은 원래 행에 남기고(키 불변 — 첫 줄이 같다),
 * 뒤 조각은 새 행(type null → 화면의 「이어서 분류」가 분류). 같은 키가 이미 있으면 만들지 않는다. 기본 dry-run, --apply 로 실행.
 */
class KakaoResplitGlued extends Command
{
    protected $signature = 'kakao:resplit-glued {--apply : 실제로 나눈다 (없으면 대상만 보여 준다)}';

    protected $description = '본문 안에 머리줄이 붙어 버린 카톡 메시지를 다시 나눈다 (11-11 파서 수정 전 적재분)';

    private const GLUED = '/\n\[[^\]\n]+\] \[(오전|오후) \d{1,2}:\d{2}\](?: |\r?\n|$)/u';

    public function handle(KakaoExportParser $parser): int
    {
        $rows = KakaoMessage::whereRaw("body LIKE '%\n[%'")->orderBy('id')->get()->filter(fn ($m) => preg_match(self::GLUED, $m->body) === 1);
        $this->line('대상 '.$rows->count().'행');
        $made = 0;
        foreach ($rows as $m) {
            $date = $m->sent_at->format('Y-m-d');
            $head = sprintf('[%s] [%s %d:%s] ', $m->sender, $m->sent_at->format('G') >= 12 ? '오후' : '오전', (int) $m->sent_at->format('g'), $m->sent_at->format('i'));
            $parts = $parser->parse($head.$m->body, $date);   // 원 행을 머리줄로 되살려 통째로 다시 파싱
            if (count($parts) < 2) {
                $this->line("  #{$m->id} 나눌 수 없음(조각 ".count($parts).')');

                continue;
            }
            $first = array_shift($parts);
            $this->line("  #{$m->id} [{$m->sender}] → 첫 조각 「".mb_substr(KakaoExportParser::firstLine($first['body']), 0, 30).'」 + 새 '.count($parts).'건: '.implode(' / ', array_map(fn ($p) => '['.$p['sender'].' '.substr($p['sent_at'], 11, 5).'] '.mb_substr(KakaoExportParser::firstLine($p['body']), 0, 25), $parts)));
            if (! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($m, $first, $parts, &$made) {
                $ruled = KakaoIngestService::ruleType($first['body']);
                $m->update(['body' => $first['body']] + ($ruled !== null && $m->type !== $ruled ? ['type' => $ruled, 'to_side' => '없음', 'ai' => $ruled === '첨부' ? null : ['rule' => $ruled]] : []));
                foreach ($parts as $p) {
                    if (KakaoMessage::where('room_id', $m->room_id)->where('msg_key', $p['msg_key'])->exists()) {
                        continue;   // 다음 읽기가 이미 제대로 넣은 행
                    }
                    $t = KakaoIngestService::ruleType($p['body']);
                    KakaoMessage::create([
                        'room_id' => $m->room_id, 'read_id' => $m->read_id, 'msg_key' => $p['msg_key'], 'sent_at' => $p['sent_at'], 'sender' => $p['sender'],
                        'body' => $p['body'], 'type' => $t, 'to_side' => $t === null ? null : '없음', 'ai' => $t === null || $t === '첨부' ? null : ['rule' => $t],
                    ]);
                    $made++;
                }
            });
        }
        $this->info($this->option('apply') ? "나눔 완료 — 새 행 {$made}건(type null 은 화면 「이어서 분류」가 분류)" : 'dry-run — --apply 로 실행');

        return self::SUCCESS;
    }
}
