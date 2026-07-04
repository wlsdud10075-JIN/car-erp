<?php

namespace App\Support;

/**
 * 카카오 알림톡 11종 템플릿 — 문구를 데이터로 보관(단일 출처).
 *
 * ⚠️ 여기 body/title 문구는 BizM 콘솔에 등록·승인된 템플릿과 **글자 하나까지 동일**해야 한다.
 *    알림톡은 발송 msg 가 승인 템플릿과 다르면 반려/실패한다. 초안 문구 원본 =
 *    docs/operations/alimtalk-templates-draft.md (BizM 등록 시 그대로 복붙). 문구 수정 시 양쪽 동시 반영.
 *
 * 변수 치환: 본문/타이틀의 `#{변수}` 를 render() 가 전달값으로 바꾼다(BizM 도 같은 자리표시자).
 * recipient: admin(대표=최고관리자) / 관리(role=관리) — 수신자 해석은 트리거 단계에서 사용.
 */
class AlimtalkTemplates
{
    public const TEMPLATES = [
        // ── 대표(admin) 5종 ──
        'erp_deal_done' => [
            'name' => '거래완료',
            'recipient' => 'admin',
            'vars' => ['차량번호', '바이어', '판매금액', '마진'],
            'title' => '거래완료',
            'body' => "[거래완료] #{차량번호}\n\n차량 한 대의 거래가 완료되었습니다.\n\n▶ 차량번호: #{차량번호}\n▶ 바이어: #{바이어}\n▶ 판매금액: #{판매금액}\n▶ 마진: #{마진}\n\nB/L 발급으로 딜이 종료되었습니다.",
        ],
        'erp_settle_pay_approve' => [
            'name' => '정산지급승인요청',
            'recipient' => 'admin',
            'vars' => ['건수', '총액'],
            'title' => '정산 지급 승인 요청',
            'body' => "[정산 지급 승인 요청]\n\n재무팀이 확정한 정산 지급 건에 대표님 승인이 필요합니다.\n\n▶ 승인 대기 건수: #{건수}건\n▶ 지급 총액: #{총액}\n\nERP에서 내용 확인 후 승인해 주세요.",
        ],
        'erp_unpaid_override_approve' => [
            'name' => '미입금우회승인요청',
            'recipient' => 'admin',
            'vars' => ['차량번호', '단계', '미수율'],
            'title' => '미입금 진입 승인 요청',
            'body' => "[미입금 진입 승인 요청] #{차량번호}\n\n입금이 완료되지 않은 차량의 다음 단계 진입에 승인이 필요합니다.\n\n▶ 차량번호: #{차량번호}\n▶ 진행 단계: #{단계}\n▶ 미수율: #{미수율}\n\nERP에서 확인 후 승인 여부를 결정해 주세요.",
        ],
        'erp_daily_summary' => [
            'name' => '일일요약',
            'recipient' => 'admin',
            'vars' => ['날짜', '매입건수', '판매건수', '완료건수', '미수총액'],
            'title' => '#{날짜} 일일 현황',
            'body' => "[일일 현황] #{날짜}\n\n어제까지의 업무 현황을 요약해 드립니다.\n\n▶ 매입: #{매입건수}대\n▶ 판매: #{판매건수}대\n▶ 거래완료: #{완료건수}건\n▶ 미수 총액: #{미수총액}\n\n자세한 내용은 ERP 대시보드에서 확인하실 수 있습니다.",
        ],
        'erp_settle_confirmed' => [
            'name' => '정산확정',
            'recipient' => 'admin',
            'vars' => ['대상월', '건수', '총액'],
            'title' => '',
            'body' => "[정산 확정 안내]\n\n재무팀이 정산을 확정 처리했습니다.\n\n▶ 대상 월: #{대상월}\n▶ 확정 건수: #{건수}건\n▶ 정산 총액: #{총액}\n\n지급 승인이 필요한 경우 별도 안내됩니다.",
        ],

        // ── [관리](role=관리) 6종 ──
        'erp_vehicle_new' => [
            'name' => '신규차량등록',
            'recipient' => '관리',
            'vars' => ['차량번호', '바이어', '매입가'],
            'title' => '',
            'body' => "[신규 차량 등록 요청]\n\n새로 매입 확정된 차량의 등록 처리가 필요합니다.\n\n▶ 차량번호: #{차량번호}\n▶ 바이어: #{바이어}\n▶ 매입가: #{매입가}\n\nERP에서 차량 정보를 확인하고 등록을 완료해 주세요.",
        ],
        'erp_clearance_docs' => [
            'name' => '통관서류준비',
            'recipient' => '관리',
            'vars' => ['차량번호', '도착일', '남은일수'],
            'title' => '통관 서류 준비',
            'body' => "[통관 서류 준비] #{차량번호}\n\n도착 예정일이 다가와 통관 서류 준비가 필요합니다.\n\n▶ 차량번호: #{차량번호}\n▶ 도착 예정일: #{도착일}\n▶ 남은 기간: #{남은일수}일\n\n미리 서류를 준비해 통관에 차질이 없도록 해주세요.",
        ],
        'erp_bl_ready' => [
            'name' => 'B/L발급',
            'recipient' => '관리',
            'vars' => ['차량번호', '바이어'],
            'title' => 'B/L 발급 가능',
            'body' => "[B/L 발급 가능] #{차량번호}\n\n잔금이 전액 입금되어 B/L 발급이 가능합니다.\n\n▶ 차량번호: #{차량번호}\n▶ 바이어: #{바이어}\n\nERP에서 B/L 문서를 발급해 주세요.",
        ],
        'erp_purchase_unpaid' => [
            'name' => '매입미지급',
            'recipient' => '관리',
            'vars' => ['건수', '총액'],
            'title' => '',
            'body' => "[매입 미지급 안내]\n\n지급일이 도래한 매입 건이 있습니다.\n\n▶ 대상 건수: #{건수}건\n▶ 미지급 총액: #{총액}\n\nERP에서 지급 대상을 확인해 주세요.",
        ],
        'erp_sale_unpaid' => [
            'name' => '판매미입금',
            'recipient' => '관리',
            'vars' => ['차량번호', '바이어', '미수금액'],
            'title' => '',
            'body' => "[판매 미입금 안내] #{차량번호}\n\n입금 확인이 필요한 판매 건이 있습니다.\n\n▶ 차량번호: #{차량번호}\n▶ 바이어: #{바이어}\n▶ 미수 금액: #{미수금액}\n\nERP에서 입금 현황을 확인해 주세요.",
        ],
        'erp_settle_pending' => [
            'name' => '정산확정대기',
            'recipient' => '관리',
            'vars' => ['건수'],
            'title' => '',
            'body' => "[정산 확정 대기 안내]\n\n거래완료로 새 정산 건이 생성되어 확정 처리가 필요합니다.\n\n▶ 확정 대기 건수: #{건수}건\n\nERP에서 내용을 확인하고 정산을 확정해 주세요.",
        ],
    ];

    /** 본문 렌더 — `#{변수}` 치환. 없는 코드면 빈 문자열. */
    public static function render(string $code, array $vars = []): string
    {
        return self::substitute(self::TEMPLATES[$code]['body'] ?? '', $vars);
    }

    /** 강조 타이틀 렌더 — 강조표기형(title 존재)만. 기본형이면 빈 문자열. */
    public static function renderTitle(string $code, array $vars = []): string
    {
        return self::substitute(self::TEMPLATES[$code]['title'] ?? '', $vars);
    }

    private static function substitute(string $text, array $vars): string
    {
        if ($text === '') {
            return '';
        }
        foreach ($vars as $key => $value) {
            $text = str_replace('#{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }
}
