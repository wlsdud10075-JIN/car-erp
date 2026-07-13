<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 카카오 알림톡(BizM) 발송 감사 로그 — 어떤 템플릿을·누구에게·언제 보냈고 성공/실패/도달했는지.
 *
 * status: sent(발송 접수 성공, msgid 있음) / failed(BizM 오류·예외) / skipped(게이트 off·미설정 등 발송 안 함).
 * report_status: delivered(실제 도달) / undelivered(미도달) / null(미확인) — alimtalk:poll-report 가 채움.
 *   ⚠️ status=sent 은 "BizM 접수 성공"까지만 의미. 실제 카톡 도달은 report_status 로만 확정된다.
 * vehicle_id·user_id 는 트리거 맥락이 있으면 채우고, 없으면(일일요약 등) null.
 */
class AlimtalkLog extends Model
{
    protected $fillable = [
        'vehicle_id', 'user_id', 'template_code', 'phone',
        'message', 'msgid', 'status', 'error',
        'report_status', 'report_checked_at', 'report_raw',
        'acknowledged_at', 'acknowledged_by',
    ];

    protected $casts = [
        'report_checked_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'report_raw' => 'array',
    ];

    /**
     * BizM /v2/sender/report 응답 1건을 도달 상태로 분류한다.
     *
     * @return string|null 'delivered' | 'undelivered' | null(미확정 — 재조회)
     *
     * code 는 "조회 성공 여부"이지 "발송 성공 여부"가 아니다(중요).
     *   - code != 'success' (예 E110:RequestMsgIdNotFound) → report 미준비/msgid 미발견 → 미확정(재조회).
     *   - code == 'success':
     *       · originMessage 존재 → 알림톡 실패(대체문자 전환 시점) → undelivered.
     *       · message 에 ':' 포함(M101:… E104:… 등 에러코드) → undelivered.
     *       · 순수 성공코드(K000/M000/R000 등, ':' 없음) → delivered.
     * (BizM 명세 예제 실측: 성공코드엔 ':' 가 없고 실패코드엔 항상 ':ErrorName' 이 붙는다.)
     */
    public static function classifyReport(?array $body): ?string
    {
        if (! is_array($body) || ($body['code'] ?? null) !== 'success') {
            return null;
        }

        $message = (string) ($body['message'] ?? '');
        $origin = (string) ($body['originMessage'] ?? '');

        if ($origin !== '' || str_contains($message, ':')) {
            return 'undelivered';
        }
        if ($message === '') {
            return null;   // 결과코드 없음 = 확정 불가
        }

        return 'delivered';
    }

    /** 관리자 주의 필요 — 발송 실패 또는 미도달인데 아직 확인(acknowledge) 안 한 건. 사이드바 배지/필터용. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere('report_status', 'undelivered'));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
