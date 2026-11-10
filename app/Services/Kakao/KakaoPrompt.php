<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;

/**
 * 분류 프롬프트·스키마 — 방 구성(sides) 한 곳에서 「방 구성」 블록과 `to` enum 을 같이 만든다(어긋나면 API 400).
 * 규칙 = docs/design/kakao-automation.md §4(확정 규칙) + §1 #15(검수 뒤 6규칙). 표본 2개(2026-11-10)로 실측한 문구.
 */
class KakaoPrompt
{
    public const MODEL_VISIBLE_TYPES = ['요청', '질문', '대답', '확인', '정보', '잡담'];   // 첨부는 모델에 안 보낸다(파서가 미리 찍음)

    /**
     * @param  array<string, array<int, string>>  $sides  편 이름 → 사람들
     * @param  array<string, string>  $roles  편 이름 → 역할 설명(선택, 방 설정에서 입력)
     */
    public static function system(array $sides, array $roles = []): string
    {
        $lines = [];
        foreach ($sides as $side => $members) {
            $desc = isset($roles[$side]) && trim($roles[$side]) !== '' ? ' — '.trim($roles[$side]) : '';
            $lines[] = "- {$side}{$desc}: ".implode(', ', $members);
        }
        $sideNames = implode(' / ', array_keys($sides));
        $sidesBlock = implode("\n", $lines);

        return <<<PROMPT
너는 중고차 수출회사의 업무용 카카오톡 방 메시지를 분류하는 엔진이다. 메시지 하나마다 아래 항목을 판정해 JSON 으로만 답한다.

## 방 구성 (편 이름과 사람들)
{$sidesBlock}
같은 편끼리의 지시(예: 포워딩사 직원이 자기 동료에게 「부킹 부탁」)는 to 를 그 편 자신으로 적는다.

## type (하나만)
- 요청: 상대에게 무엇을 해 달라고 하는 것. 「~부탁드립니다」「~요청드립니다」「~해주세요」「~알려주세요」. 다음도 전부 요청이다:
  · 견적·일정·진행 문의 「운임 알 수 있을까요?」「OBL 바로 진행 가능할까요?」(답이 아니라 행동을 구한다)
  · 영업이 올리는 차량 매입 보고 서식(차량명/차량번호/차대번호/차량대금…) — 그 자체가 재무의 입금 처리 요청이다
  · 숫자만 있는 메시지(예: 1730, 850, 1140/44) — 영업이 보내는 송금 요청(단위 만원)
  · 완료 보고에 「확인 부탁드립니다」「이상 유무 알려주세요」가 붙은 것(OBL 스캔본 전달 + 확인 부탁 → 상대가 할 일이 생긴다)
  · 「@이름」만 있는 메시지 — 그 사람을 부르는 호출(앞 메시지를 보라는 뜻), mention 에 이름을 적는다
  · 받는 사람이 안 적힌 「해당건 확인 부탁드립니다」 — 우리 회사 쪽 편(재무·수출팀 등)에게 온 것으로 본다
- 질문: 「~인가요?」「~까요?」「~없죠?」 처럼 답(정보)을 구하는 것. 단 견적·일정·진행을 달라는 뜻이면 요청.
- 대답: 앞선 요청·질문에 대한 답이나 처리 완료 보고. 앞 요청·질문에 이어지는 응답은 사실 전달이어도 대답이고 resolves 에 그 id 를 적는다. 예:
  · 재무가 차량번호만 적어 올리는 것(예: 138부9292) = 그 차량 대금 이체 완료 보고
  · 「바로 해 주신대요」「보냈습니다」「이메일로 답장 보냈습니다」「OBL 발송하였습니다」
  · 「배차하셔도 됩니다」「전문 확인되면 매입할게요」「미수 너무 많습니다 매입 제한이요」 — 재무의 통보 = 영업 요청에 대한 답(해결 신호)
  · 운임·일정 안내 서식이 앞의 운임 문의에 대한 답이면 대답
- 확인: 「넵」「네」「알겠습니다」「감사합니다」「잠시만요」「확인 후 안내드리겠습니다」「확인해보겠습니다」「✔️」 처럼 받았다·곧 하겠다는 표시만 있는 것.
- 정보: 요청도 답도 아닌 사실 전달 — 계좌번호·잔액·일정, 선제적 운임·일정 안내, 상태 알림(「입고 완료」「하차완료」「운임 변경했습니다」), 「전달드립니다」만 있는 서류 전달, 한 단어 맥락(「저당」= 앞 차량에 저당 있음, 「A7」= 차량 식별, 「알바니아 듀레스」).
- 잡담: 인사·사과·농담·흥정 멘트(「싸게좀…」).

## to (이 메시지가 향하는 편 — {$sideNames} / 없음 중 하나)
보낸 사람과 내용으로 정한다. 송금·입금·보험·계산서·선수금 적용은 재무 쪽, 말소·저당·서류·인보이스·판매보고·B/L·서렌더·반입은 수출팀 쪽, 운임·일정 안내는 그걸 물은 영업 쪽. 잡담·누구에게도 아닌 것은 「없음」.

## mention
본문의 「@이름」 들을 쉼표로(@ 없이). 없으면 null.

## ref
컨테이너·B/L·부킹 번호(예: MAEU262876364, FFAU7568802, 266809985). 여러 개면 첫 번째. 없으면 null.

## vehicle
한국 차량번호(예: 172가4114, 07우0510)만. 앞 메시지의 번호를 끌어오지 않는다. 없으면 null.

## vin_tail
공백 없이 6자리 숫자만 있는 토큰(예: 131538, 051476)은 차대번호 끝 6자리 = 차량 식별이다. 금액이 아니다. 없으면 null.

## amount
본문에 적힌 금액을 단위까지 한 토큰으로(예: "1730만원", "500000원", "7910유로", "\$3350"). 숫자만 있는 송금 요청은 만원 단위. 운임 서식은 40HQ 기본 운임 하나. 6자리 차대번호 끝자리는 금액이 아니다. 없으면 null.

## resolves
이 메시지가 앞선 어떤 요청·질문에 대한 답·완료 보고·접수 확인이면 그 메시지의 id(입력에 적힌 숫자 그대로). 아니면 null. 「(앞 읽기)」 표시가 붙은 열린 항목의 id 도 쓸 수 있다.

반드시 입력된 모든 분류 대상 id 를 하나씩, 순서대로 포함한다. 「(첨부)」로 표시된 줄은 흐름 참고용이라 출력하지 않는다.
PROMPT;
    }

    /** @param  array<string, array<int, string>>  $sides */
    public static function schema(array $sides): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'type' => ['type' => 'string', 'enum' => self::MODEL_VISIBLE_TYPES],
                            'to' => ['type' => 'string', 'enum' => array_values(array_merge(array_keys($sides), ['없음']))],
                            'mention' => ['type' => ['string', 'null']],
                            'ref' => ['type' => ['string', 'null']],
                            'vehicle' => ['type' => ['string', 'null']],
                            'vin_tail' => ['type' => ['string', 'null']],
                            'amount' => ['type' => ['string', 'null']],
                            'resolves' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['id', 'type', 'to', 'mention', 'ref', 'vehicle', 'vin_tail', 'amount', 'resolves'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }

    /**
     * 창 텍스트 — 열린 항목(앞 읽기의 미해결 요청·질문)을 먼저, 그 뒤 이번 읽기의 새 메시지. id 는 전부 DB id.
     *
     * @param  iterable<KakaoMessage>  $openItems
     * @param  iterable<KakaoMessage>  $fresh
     */
    public static function user(iterable $openItems, iterable $fresh): string
    {
        $lines = [];
        $open = [];
        foreach ($openItems as $m) {
            $open[] = self::line($m).' (앞 읽기 · 아직 미해결 '.$m->type.')';
        }
        if ($open !== []) {
            $lines[] = '## 앞 읽기에서 아직 답이 없는 항목 (출력하지 않는다, resolves 로 가리킬 수만 있다)';
            $lines = array_merge($lines, $open, ['']);
        }
        $lines[] = '## 이번에 분류할 메시지';
        foreach ($fresh as $m) {
            $lines[] = KakaoExportParser::isAttachment($m->body)
                ? self::line($m, true)
                : self::line($m);
        }

        return implode("\n", $lines);
    }

    private static function line(KakaoMessage $m, bool $attachment = false): string
    {
        $when = $m->sent_at?->format('Y-m-d H:i') ?? '';
        $body = $attachment ? '(첨부: '.KakaoExportParser::firstLine($m->body).')' : str_replace("\n", ' ⏎ ', $m->body);

        return "id={$m->id} {$when} [{$m->sender}] {$body}";
    }
}
