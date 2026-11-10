<?php

namespace App\Services\Kakao;

use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * 한 메시지를 「내 카톡 할 일」의 묶음 9개 중 하나로 — 순수 함수. 입력 = 방향·사용자 상태·시각·답 유무. 임계값은 Setting.
 * 기획 §5(묶음 순서·조건) · §1 #15 ⑦(팀 동료 진행 중).
 *
 *   missed   놓쳤을 가능성 — 미룸 시각이 지남 · 내 일이 N일 넘게 방치
 *   now      지금 할 일     — 내 일 중 돈·마감·재촉 키워드가 있거나 N시간 넘은 것
 *   today    오늘 안에      — 나머지 내 일
 *   waiting  답 기다리는 중 — 내가 보낸 요청·질문에 아직 답이 없음
 *   snoozed  미뤄둔 일      — 미룸(시각 전) · 조건형 미룸(상대 답하면/ERP 상태 바뀌면)
 *   teammate 팀 동료 진행 중 — 같은 편 동료의 요청·질문(접힘)
 *   done     오늘 끝난 것   — 오늘 완료한 것 · 답이 달려 자동 완료 후보인 것(auto)
 *   review   확인 필요      — 분류 실패(type null)
 *   none     나와 무관      — 남들끼리의 요청·질문 (그 밖의 무관은 목록에 안 올린다 → null)
 */
class KakaoBuckets
{
    public const ORDER = ['missed', 'now', 'today', 'waiting', 'snoozed', 'teammate', 'done', 'review', 'none'];

    public const LABELS = [
        'missed' => '놓쳤을 가능성', 'now' => '지금 할 일', 'today' => '오늘 안에', 'waiting' => '답 기다리는 중',
        'snoozed' => '미뤄둔 일', 'teammate' => '팀 동료 진행 중', 'done' => '오늘 끝난 것', 'review' => '확인 필요', 'none' => '나와 무관',
    ];

    public const HINTS = [
        'missed' => '마감·재확인 시점이 지났거나 며칠째 그대로인 것 — 확정이 아니라 가능성',
        'now' => '돈이 막히거나 시간이 민감한 것, 하루 넘게 묵은 것',
        'today' => '막힌 사람 없음 · 단순 전달 — 오늘 안에',
        'waiting' => '내가 보낸 요청·질문에 아직 답이 없음',
        'snoozed' => '언제까지 미룸 · 사유',
        'teammate' => '같은 편 동료가 진행 중인 요청',
        'done' => '오늘 완료한 것 · 답이 달려 자동 완료 후보인 것',
        'review' => '뜻을 못 정한 메시지 — 분류를 직접 정해 주세요',
        'none' => '남들끼리의 요청',
    ];

    /** 「지금 할 일」로 올리는 이유 키워드 — 돈·마감·재촉 */
    //   ⚠️ 부분 일치라 짧은 조각은 넣지 않는다 — 'BL' 은 'OBL' 에, '급' 은 '지급·급여' 에 걸린다
    public const URGENT_WORDS = ['입금', '송금', '계약금', '잔금', '미수', '마감', 'B/L', '비엘', 'OBL', '홀딩', '서렌더', '긴급', '급해', '급합', '꼭', '오늘', '빨리'];

    /**
     * @return array{bucket: ?string, reason: string, auto: bool} bucket null = 목록에 안 올림
     */
    public static function of(string $direction, KakaoMessage $message, ?KakaoItem $item, bool $hasReply, CarbonInterface $now): array
    {
        $type = $item?->type_override ?? $message->type;
        $state = $item?->state;

        if ($state === 'ignored') {
            return self::r(null, '무시함');
        }
        if ($state === 'not_mine') {
            return self::r('none', '내 일 아님으로 표시');
        }
        if ($state === 'done') {
            return $item->done_at && $item->done_at->isSameDay($now) ? self::r('done', '오늘 완료') : self::r(null, '완료');
        }
        if ($type === null && $message->type !== '첨부') {
            return self::r('review', '분류 실패 — 뜻을 정해 주세요');
        }
        if ($state === 'snoozed') {
            if ($item->snooze_until) {
                return $item->snooze_until->lte($now)
                    ? self::r('missed', '미룬 시점('.$item->snooze_until->format('m/d H:i').') 지남')
                    : self::r('snoozed', $item->snooze_until->format('m/d H:i').'까지 미룸'.($item->snooze_reason ? ' · '.$item->snooze_reason : ''));
            }

            return self::r('snoozed', $item->snooze_reason ?: '조건이 될 때까지 미룸');
        }

        $ageHours = $message->sent_at ? $message->sent_at->diffInHours($now) : 0;

        switch ($direction) {
            case KakaoDirection::WAITING:
                return $hasReply ? self::r('done', '답이 달림 — 응답 확인', true) : self::r('waiting', self::agoLabel($ageHours).' 전에 보냄');
            case KakaoDirection::TEAMMATE:
                return $hasReply ? self::r(null, '동료 요청에 답 달림') : self::r('teammate', '동료 요청');
            case KakaoDirection::MINE:
                if ($hasReply) {
                    return self::r('done', '이미 답이 달림 — 확인만', true);
                }
                if ($ageHours >= Setting::kakaoMissedDays() * 24) {
                    return self::r('missed', self::agoLabel($ageHours).'째 그대로');
                }
                $word = self::urgentWord($message->body);
                if ($word !== null) {
                    return self::r('now', '「'.$word.'」');
                }
                if ($ageHours >= Setting::kakaoUrgentHours()) {
                    return self::r('now', self::agoLabel($ageHours).' 지남');
                }

                return self::r('today', '단순 처리');
            default:
                return in_array($type, ['요청', '질문'], true) ? self::r('none', '남들끼리의 요청') : self::r(null, '무관');
        }
    }

    public static function urgentWord(string $body): ?string
    {
        foreach (self::URGENT_WORDS as $w) {
            if (mb_stripos($body, $w) !== false) {
                return $w;
            }
        }

        return null;
    }

    private static function agoLabel(int $hours): string
    {
        return $hours < 24 ? $hours.'시간' : intdiv($hours, 24).'일';
    }

    private static function r(?string $bucket, string $reason, bool $auto = false): array
    {
        return ['bucket' => $bucket, 'reason' => $reason, 'auto' => $auto];
    }
}
