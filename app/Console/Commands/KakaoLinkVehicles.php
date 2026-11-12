<?php

namespace App\Console\Commands;

use App\Models\KakaoMessage;
use App\Models\KakaoMessageVehicle;
use App\Services\Kakao\KakaoVehicleLinks;
use Illuminate\Console\Command;

/**
 * 카톡 메시지 ↔ ERP 차량 연결을 지난 글에도 채운다(jin 11-11 §14 ② — 새 글은 읽을 때 저절로 채워진다).
 * 최근 N일 메시지마다 본문 식별자(self) → 그다음 짧은 요청의 이어받기(inherit). 이미 줄이 있는 글은 건드리지 않는다(두 번 돌려도 같다).
 * 기본 dry-run(몇 건이 생길지만), --apply 로 쓴다. 분류(모델 호출)는 하지 않는다 — 비용 0.
 */
class KakaoLinkVehicles extends Command
{
    protected $signature = 'kakao:link-vehicles {--days=7 : 최근 며칠 메시지} {--apply : 실제로 쓴다 (없으면 대상만 보여 준다)}';

    protected $description = '카톡 메시지에 ERP 차량 연결(여러 대·이어받기)을 지난 글까지 채운다';

    public function handle(): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $messages = KakaoMessage::where('sent_at', '>=', $since)->whereDoesntHave('vehicleLinks')->orderBy('sent_at')->orderBy('id')->get();
        $withIds = $messages->filter(fn ($m) => KakaoVehicleLinks::identifiers((string) $m->body) !== []);
        $short = $messages->filter(fn ($m) => KakaoVehicleLinks::isShortRequest($m));
        $this->line("대상 {$messages->count()}건 — 본문에 차가 있는 글 {$withIds->count()} · 이어받기 후보(짧은 요청·질문) {$short->count()}");

        if (! $this->option('apply')) {
            $inherit = $short->filter(fn ($m) => KakaoVehicleLinks::inheritSource($m) !== null)->count();   // 원천 판정은 본문만 본다 — self 를 쓰기 전에도 같은 답
            $this->line("  이어받을 글(예상) {$inherit}건. 쓰려면 --apply");

            return self::SUCCESS;
        }

        KakaoVehicleLinks::linkSelf($withIds);
        KakaoVehicleLinks::linkInherited($short);
        $self = KakaoMessageVehicle::whereIn('message_id', $withIds->pluck('id'))->count();
        $inh = KakaoMessageVehicle::whereIn('message_id', $short->pluck('id'))->where('via', 'inherit')->distinct('message_id')->count('message_id');
        $this->info("썼습니다 — self {$self}줄 · 이어받은 글 {$inh}건");

        return self::SUCCESS;
    }
}
