<?php

namespace App\Services\Kakao;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 담당 분야 — 사용자가 「내가 맡는 일」을 고르면, 그 분야가 아닌 요청은 「팀 동료 진행 중」으로 접힌다(jin 2026-11-11 · 설계 §13).
 * 분야 목록·설명은 여기 한 곳(분류 프롬프트·스키마·화면 칩·처음 고르기 창이 같이 쓴다).
 */
class KakaoTopics
{
    /** 분류기가 찍는 값(「기타」 = 어느 분야도 아님 → 전원에게) */
    public const ALL = ['등록', '말소', '통관', '선적', '입금', '보험', '서류', '기타'];

    /** 사용자가 고르는 칩(「전체」 = 팀장, 전부 내 일) */
    public const PICKABLE = ['등록', '말소', '통관', '선적', '입금', '보험', '서류', '전체'];

    public const HINTS = [
        '등록' => 'ERP 차량 등록·등록 정보(차량번호·차대번호·차종·매입금액·판매금액 입력/수정), 차량등록증',
        '말소' => '말소·저당 해지·말소증·신분증 서류',
        '통관' => '수출 면허·반입·신고 진행, 관세사와 주고받는 진행 문의',
        '선적' => '부킹·배차·VSL·ETA·컨테이너 작업·선적 일정·운임',
        '입금' => '계약금·차량대금·송금·입금 확인·미수·환율(재무)',
        '보험' => '보험 가입·해지·보험 명세',
        '서류' => '면장·B/L·OBL·서렌더·인보이스·패킹리스트·선적지시서·통관서류 발송',
        '전체' => '팀장 — 모든 분야가 내 일',
    ];

    /** 이 사용자가 고른 분야. null = 아직 안 고름(전부 보기). */
    public static function of(User $user): ?array
    {
        $json = DB::table('kakao_user_topics')->where('user_id', $user->id)->value('topics');
        if ($json === null) {
            return null;
        }
        $t = json_decode((string) $json, true);

        return is_array($t) ? array_values(array_intersect(self::PICKABLE, $t)) : null;
    }

    public static function save(User $user, array $topics): void
    {
        $topics = array_values(array_intersect(self::PICKABLE, $topics));
        DB::table('kakao_user_topics')->updateOrInsert(['user_id' => $user->id], ['topics' => json_encode($topics, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * 이 요청을 이 사람이 「내 일」로 볼까 — 분야로 거른다. 안 골랐거나 「전체」·빈 선택 = 전부 내 일.
     * 분야를 모르는 글(null — 이 기능 전 분류분)·「기타」 = 내 일(놓치느니 보인다).
     */
    public static function isMine(?array $picked, ?string $topic): bool
    {
        if ($picked === null || $picked === [] || in_array('전체', $picked, true)) {
            return true;
        }
        if ($topic === null || $topic === '' || $topic === '기타') {
            return true;
        }

        return in_array($topic, $picked, true);
    }
}
