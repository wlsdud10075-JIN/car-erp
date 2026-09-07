<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 말소 처리 재촉 알림톡 (erp_deregistration_reminder) — 매일 아침, 담당 영업에게 목록형 1건.
 *
 * 대상 = scopeAction('deregistration_needed') 위에 **좁히는 조건 3개**를 얹는다(조건 재작성 금지, SKILLS §8 #44):
 *   ① is_deregistered = false      — 말소 «자체» 미처리만. 해소 = 말소 체크.
 *   ② container_number 없음        — 이미 컨테이너가 잡혔으면 그만 보낸다 (jin 2026-09-07)
 *   ③ export_declaration_number 없음 — 수출신고번호가 나왔으면 그만 보낸다 (jin 2026-09-07)
 *
 * 🚨 **①이 발송량의 핵심이다.** scopeAction 원본은 `(is_deregistered=false OR 서류없음)` 이라
 *    「말소는 했고 말소등록증 파일만 안 올린」 차까지 잡는다 — ssancarerp 실측 57대 중 **41대(72%)**
 *    가 그쪽이었다. 그대로 두면 본문의 "말소 처리해 주세요" 가 대다수에게 거짓 재촉이 된다.
 *    jin 2026-09-07 결정 = **말소 자체 미처리만**(16대). 서류 미등록은 대시보드 할일에만 남는다.
 *
 * 🚨 **②③ 이 없으면 매일 폭주한다** — 실측 ssancarerp 346대(이용빈 74·무사백 65). 완납은 «평소 상태»라
 *    픽업(미완납 = 자연히 소수)과 달리 후보가 재고 전체로 벌어진다. 두 조건이 346 → 57 로 깎았다.
 *
 * ⚠️ 빈 문자열 두 갈래(`whereNull` + `= ''`)는 실측 스크립트와 **같은 조건**이어야 346→57→16 이 재현된다.
 *    한쪽만 쓰면 빈 문자열로 저장된 행이 조용히 빠져나간다.
 *
 * 목록형 1통 = 사람당 하나(jin 2026-09-07). 차량 1대 = 1통이면 임윤태 13통/일이 된다.
 */
class AlimtalkDeregistration extends Command
{
    protected $signature = 'alimtalk:deregistration';

    protected $description = '매입 완납 +2일 & 말소 미처리 차량 목록 알림톡(1건) — 담당 영업.';

    /** 완납 후 며칠 지나야 재촉하나 (jin 2026-09-07). */
    private const GRACE_DAYS = 2;

    /** 본문 1000자 상한 대비 목록 줄 수 상한. 초과분은 "외 N건" — 상세는 차량관리. */
    private const LIST_CAP = 15;

    public function handle(): int
    {
        try {
            $rows = Vehicle::query()
                ->action('deregistration_needed')
                ->where('is_deregistered', false)
                ->where(fn ($q) => $q->whereNull('container_number')->orWhere('container_number', ''))
                ->where(fn ($q) => $q->whereNull('export_declaration_number')->orWhere('export_declaration_number', ''))
                // ⚠️ warehouse_in_date(매입 완납일)가 purchaseBalancePayments 컬렉션을 읽는다 — eager load 필수.
                //    AlimtalkSaleUnpaid 를 복제하면 이 줄이 빠진다(그쪽은 캐시 컬럼만 읽어 필요 없다).
                ->with(['salesman', 'purchaseBalancePayments'])
                ->get();

            if ($rows->isEmpty()) {
                $this->info('deregistration: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            // 🎯 사람마다 **자기가 볼 수 있는 차만** 담아 보낸다 (SCOPED_CODES).
            //    영업 = 본인 담당분 / 관리 = 본인 팀 / admin·업무관리자 = 전체.
            //    ⚠️ 담당자 없는 차는 영업 스코프 밖이라 admin·업무관리자를 안 켜면 아무도 못 받는다(§8 #61).
            $targets = AlimtalkRecipients::scopedFor('erp_deregistration_reminder', $rows);
            if (empty($targets)) {
                $this->info('deregistration: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($targets as $phone => $mine) {
                // 유예는 **대상 판정이 아니라 목록에서 빼는 것**이다(목록형이라 픽업과 다르다).
                //   그 사람 차가 전부 유예 안이면 이번 회차는 통째로 skip — 빈 목록을 보내지 않는다.
                $due = collect($mine)->filter(fn (Vehicle $v) => $this->elapsedDays($v) !== null
                    && $this->elapsedDays($v) >= self::GRACE_DAYS);
                if ($due->isEmpty()) {
                    continue;
                }

                // 카드(아이템리스트) = 「몇 대인가」 + 양 끝 한 대씩, 본문 = 전체 목록.
                $sorted = $due->sortByDesc(fn (Vehicle $v) => $this->elapsedDays($v))->values();
                $svc->send('erp_deregistration_reminder', $phone, [
                    '건수' => (string) $sorted->count(),
                    '최장차량' => $this->oneLine($sorted->first()),
                    '최근차량' => $this->oneLine($sorted->last()),
                    '말소목록' => $this->listOf($sorted),
                ]);
                $sent++;
            }
            $this->info("deregistration: {$rows->count()}대 → {$sent}명 발송 시도.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:deregistration 실패', ['error' => $e->getMessage()]);
            $this->error('deregistration 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 매입 완납 후 경과일. 완납일을 못 구하면 null(=이번 회차 제외).
     *
     * 완납일 = `Vehicle::warehouse_in_date`(재고관리 「입고일」과 같은 출처) = **마지막 확정 매입잔금의
     * 지급일**. 잔금은 2~3회로 쪼개 지급되는 일이 흔해(운영 실측 239대 중 24대) 첫 지급일을 쓰면
     * 경과일이 실제보다 부풀려진다.
     *
     * 🚫 매입일 폴백을 넣지 말 것 — 「PBP 없이 완납」은 도달 불가다. `purchase_paid_amount` 가
     *    **확정 PBP 합만** 세고 `down_payment` 를 안 세기 때문에(`Vehicle.php:2074`), 완납이려면
     *    잔금 행이 최소 1건 있어야 한다. 후보 쿼리(`purchaseUnpaidRawExpr`)도 같은 정의라 어긋나지
     *    않는다. 실측 3사 0대. null 검사는 예외 방지용 잔여 방어일 뿐이다.
     * ⚠️ Carbon 3 의 diffInDays 는 **부호 있는 값**($대상 − $기준)이라 방향을 뒤집으면 음수가 된다(§8 #34).
     */
    private function elapsedDays(Vehicle $v): ?int
    {
        $paidOn = $v->warehouse_in_date;

        return $paidOn
            ? (int) $paidOn->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : null;
    }

    /** 카드 아이템 한 줄 — 차량번호 + 경과일. description 20자 상한(조립 지점 자동컷) 안에 들어가는 길이. */
    private function oneLine(?Vehicle $v): string
    {
        return $v ? sprintf('%s · %d일', $v->vehicle_number, $this->elapsedDays($v)) : '-';
    }

    /** 본문에 들어갈 여러 줄 목록. 구입처는 길이가 들쭉날쭉해(실측 25~30자) 잘라 넣는다(§8 #35 의 그 필드). */
    private function listOf(Collection $rows): string
    {
        $lines = $rows->take(self::LIST_CAP)->map(fn (Vehicle $v) => sprintf(
            '▶ %s · %s · 완납 %d일 경과',
            $v->vehicle_number,
            mb_substr((string) ($v->purchase_from ?: '-'), 0, 12),
            $this->elapsedDays($v)
        ))->implode("\n");

        return $rows->count() > self::LIST_CAP
            ? $lines."\n▶ 외 ".($rows->count() - self::LIST_CAP).'건'
            : $lines;
    }
}
