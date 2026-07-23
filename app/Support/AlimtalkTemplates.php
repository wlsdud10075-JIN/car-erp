<?php

namespace App\Support;

/**
 * 카카오 알림톡 템플릿 — 문구를 데이터로 보관(단일 출처). 2026-07-06 재구성(jin): 대표 3·관리 5·영업 1·딜러 1 = 10종.
 *
 * ⚠️ 여기 body 문구는 BizM 콘솔에 등록·승인된 템플릿과 **글자 하나까지 동일**해야 한다.
 *    알림톡은 발송 msg 가 승인 템플릿과 다르면 반려/실패한다. 초안 원본 =
 *    docs/operations/alimtalk-templates-draft.md (BizM 등록 시 그대로 복붙). 문구 수정 시 양쪽 동시 반영.
 *
 * 🟦 아이템리스트형 9종 (2026-07-15) — 승인본은 msg(body)를 짧게 두고 숫자 데이터는 카드(ITEMLIST)로 뺐다.
 *    ITEMLIST[code] 존재 = 아이템리스트형 → BizmAlimtalkService 가 header+items payload 를 함께 발송.
 *    5종(sale_unpaid·eta_balance_due·shipping_due·settle_pending·deregistration_notice)은 가변목록/링크라 기본형 유지.
 *
 * 변수 치환: 본문·아이템의 `#{변수}` 를 substitute() 가 전달값으로 바꾼다(BizM 도 같은 자리표시자).
 * recipient: admin(대표) / 관리(role=관리) / 영업(role=영업) / dealer(국내 딜러) — 수신자 해석은 트리거 단계.
 *   (용어 — jin 2026-07-06: 바이어=해외 구매자만, 국내 구매처=딜러.)
 */
class AlimtalkTemplates
{
    public const TEMPLATES = [
        // ── 대표(admin) 3종 ──
        'erp_daily_summary' => [
            'name' => '일일요약',
            'recipient' => 'admin',
            // 이번 달 누적 매출 + 현재 시점 미수(선적 전/후). 정의=채권관리 G3 분류·관리자 대시보드 매출.
            'vars' => ['날짜', '판매건수', '매출액', '선적전건수', '선적전금액', '선적후건수', '선적후금액', '미수합계'],
            'title' => '',
            'body' => "[일일 현황] #{날짜}\n\n이번 달 매출과 현재 미수 현황입니다.\n채권관리에서 자세히 확인하실 수 있습니다.",
        ],
        'erp_weekly_summary' => [
            'name' => '주간요약',
            'recipient' => 'admin',
            // 금요일 18:00. 이번 주 매출 + 현재 미수 + 담당자별 실적(가변 → 한 변수 여러 줄).
            'vars' => ['주간', '판매건수', '매출액', '선적전건수', '선적전금액', '선적후건수', '선적후금액', '담당자실적'],
            'title' => '',
            'body' => "[주간 현황] #{주간}\n\n이번 주 매출과 미수 현황입니다.\n\n■ 담당자별 실적\n#{담당자실적}\n\n채권관리에서 자세히 확인하실 수 있습니다.",
        ],
        'erp_monthly_closing' => [
            'name' => '월결산요약',
            'recipient' => 'admin',
            // 매월 10일. 전월(귀속월) 결산 마무리 보고. 회사이익=총마진−지급총액+환차(회사순이익, 관리자 대시보드 companyProfit 동일). 인원별지급=가변.
            'vars' => ['대상월', '총매출', '총마진', '지급총액', '회사이익', '인원별지급'],
            'title' => '',
            'body' => "[월 결산 보고] #{대상월}\n\n#{대상월} 결산이 마무리되었습니다.\n\n■ 인원별 지급\n#{인원별지급}\n\n정산에서 자세히 확인하실 수 있습니다.",
        ],

        // ── 관리(role=관리) 5종 ──
        'erp_vehicle_new' => [
            'name' => '신규차량등록',
            'recipient' => '관리',
            'vars' => ['차량번호', '바이어', '매입가'],
            'title' => '',
            'body' => "[신규 차량 등록 요청]\n\n새로 매입 확정된 차량의 등록 처리가 필요합니다.\nERP에서 차량 정보를 확인하고 등록을 완료해 주세요.",
        ],
        'erp_purchase_unpaid' => [
            'name' => '매입미지급',
            'recipient' => '관리',
            // 매일 아침 요약 1건(지급일 도래분 묶음).
            'vars' => ['건수', '총액'],
            'title' => '',
            'body' => "[매입 미지급 안내]\n\n지급일이 도래한 매입 건이 있습니다.\nERP에서 지급 대상을 확인해 주세요.",
        ],
        'erp_sale_unpaid' => [
            'name' => '판매미입금',
            'recipient' => '관리',
            // 목록형 1건(차량 여러 대를 한 통에) — 건건이 폭주 방지. 대상=scopeAction('sale_unpaid', grace 10일 제외 반영). 상세=채권관리.
            'vars' => ['미입금목록'],
            'title' => '',
            'body' => "[판매 미입금 안내]\n\n입금 확인이 필요한 판매 건이 있습니다.\n\n#{미입금목록}\n\n자세한 내용은 ERP 「채권관리」 메뉴에서 확인·처리해 주세요.",
        ],
        'erp_settle_pending' => [
            'name' => '정산확정대기',
            'recipient' => '관리',
            'vars' => ['건수'],
            'title' => '',
            'body' => "[정산 확정 대기 안내]\n\n거래완료로 새 정산 건이 생성되어 확정 처리가 필요합니다.\n\n▶ 확정 대기 건수: #{건수}건\n\nERP에서 내용을 확인하고 정산을 확정해 주세요.",
        ],
        'erp_eta_balance_due' => [
            'name' => 'ETA잔금미완납',
            'recipient' => '관리',
            // 목록형 1건 — ETA(도착일) 7일 전 & 잔금 미완납. 도착 전 마지막 100% 완납 재촉(선적일 알림과 별개 유지). 상세=채권관리.
            'vars' => ['잔금목록'],
            'title' => '',
            'body' => "[도착 임박 잔금 안내]\n\n도착 예정일이 임박했으나 잔금이 남은 차량이 있습니다.\n\n#{잔금목록}\n\n자세한 내용은 ERP 「채권관리」 메뉴에서 확인·처리해 주세요.",
        ],
        'erp_shipping_due' => [
            'name' => '선적임박미수',
            'recipient' => '관리',
            // 선적일 5일 전 & 미완납(100% 미달) 차량 요약. <50%(입금우회 진행)는 [50%미만·사유] 로 강조.
            //   목록=가변 → ERP가 한 변수 여러 줄로 채움(차량별: 번호·선적 D-day·미수율·[50%미만·사유]).
            'vars' => ['선적미수목록'],
            'title' => '',
            'body' => "[선적 임박 미수 안내]\n\n선적일이 다가오는데 잔금이 남은 차량이 있습니다.\n\n#{선적미수목록}\n\n선적 전 잔금 완납이 필요합니다.\n자세한 내용은 ERP 「채권관리」 메뉴에서 확인·처리해 주세요.\n※ [50%미만] 표시는 입금 50% 미만으로 우회 진행된 차량이며 승인 사유가 함께 표시됩니다.",
        ],

        // 매매상 잔금 (karaba, 2026-07-22) — 매매상 체크(is_dealer_purchase) + 계약금 후 잔금 기한 임박/경과 차량 목록형 1건.
        //   수신=내부 '관리'(외부 매매상 아님). 트리거=일일 스케줄(karaba 프로파일). 기존 erp_eta_balance_due 와 동일 패턴.
        //   ⚠️ BizM 승인본과 글자 하나까지 동일 유지 (등록 코드 = erp_dealer_balance_due, 카테고리 008002).
        'erp_dealer_balance_due' => [
            'name' => '매매상잔금',
            'recipient' => '관리',
            'vars' => ['잔금목록'],
            'title' => '',
            'body' => "[매매상 잔금 안내]\n\n매매상 계약 후 잔금 기한이 임박했거나 지난 차량이 있습니다.\n\n#{잔금목록}\n\n기한 내 잔금 지급이 필요합니다.\n자세한 내용은 ERP 「차량관리」에서 확인해 주세요.",
        ],

        // 보증금 매입 바이어 입금 독촉 2종 (2026-07-23, jin) — 목록형 기본형(가변 #{목록}). 카테고리 008002.
        //   ⚠️ BizM 승인본(회사폴더 upload_erp_*_아이템리스트.xlsx row20/21)과 글자 하나까지 동일 유지.
        //   ① due  = 도장+5일~10일, 담당 영업(본인 차) + 관리(전체) 독촉.
        'erp_deposit_cash_due' => [
            'name' => '보증금매입독촉',
            'recipient' => '관리',
            'vars' => ['보증금목록'],
            'title' => '',
            'body' => "[보증금 매입 · 바이어 입금 필요]\n\n보증금으로 매입금을 선지급했으나 바이어 판매입금이 기준에 못 미쳐 선적이 보류 중인 차량입니다.\n\n#{보증금목록}\n\n바이어에게 입금 독촉 후, ERP 「채권관리」에서 확인·처리해 주세요.",
        ],
        //   ② overdue = 도장+10일 초과, 대표에게 처분 판단 요청(독촉 대상에선 제외).
        'erp_deposit_cash_overdue' => [
            'name' => '보증금매입초과-대표',
            'recipient' => '대표',
            'vars' => ['초과목록'],
            'title' => '',
            'body' => "[보증금 매입 · 처분 판단 요청]\n\n보증금으로 매입한 차량 중 바이어 판매입금 기한이 초과됐으나 아직 기준에 미달한 건입니다.\n아래 차량의 처분 방향(회수·연장·취소 등)을 검토해 주세요.\n\n#{초과목록}\n\n상세는 ERP 「채권관리」에서 확인하실 수 있습니다.",
        ],

        // 보증금 매입 선지급 승인 알림 3종 (2026-07-23, jin) — 정산 지급 승인 사다리와 동일 패턴.
        //   요청=기안 시 관리(승인) / 관리승인 시 재무(확정) · 결과=재무확정 시 기안자 완료 / 반려 시 기안자.
        //   ⚠️ BizM 승인본(회사폴더 xlsx row22~24)과 글자 하나까지 동일 유지. 기본형. 카테고리 008002.
        'erp_deposit_funding_request' => [
            'name' => '보증금선지급 승인요청',
            'recipient' => '관리/재무',
            'vars' => ['차량번호', '금액', '기안자'],
            'title' => '',
            'body' => "[보증금 선지급 승인 요청]\n\n보증금 매입 선지급 건이 승인 대기 중입니다.\nERP 승인/이체 화면에서 확인 후 처리해 주세요.\n\n▶ 대상 차량: #{차량번호}\n▶ 선지급액: #{금액}원\n▶ 기안자: #{기안자}",
        ],
        'erp_deposit_funding_done' => [
            'name' => '보증금선지급 완료',
            'recipient' => '기안자',
            'vars' => ['차량번호', '금액'],
            'title' => '',
            'body' => "[보증금 선지급 완료]\n\n기안하신 보증금 매입 선지급이 최종 확정(지급) 처리되었습니다.\n\n▶ 대상 차량: #{차량번호}\n▶ 선지급액: #{금액}원",
        ],
        'erp_deposit_funding_rejected' => [
            'name' => '보증금선지급 반려',
            'recipient' => '기안자',
            'vars' => ['차량번호', '사유'],
            'title' => '',
            'body' => "[보증금 선지급 반려]\n\n기안하신 보증금 매입 선지급이 반려되었습니다.\n내용을 확인해 주세요.\n\n▶ 대상 차량: #{차량번호}\n▶ 사유: #{사유}",
        ],

        // ── 영업(role=영업) 1종 — 픽업 재촉 ──
        'erp_pickup_reminder' => [
            'name' => '픽업필요',
            'recipient' => '영업',
            // 매입일+2일 경과 & 매입 미완납(purchase_unpaid>0, 계약금/잔금 필드 무관). 해소=매입 완납.
            'vars' => ['차량번호', '구입처', '미지급금액', '매입일', '경과일'],
            'title' => '',
            'body' => "[차량 픽업 필요] #{차량번호}\n\n차량 픽업이 되지 않아 매입 대금이 완납되지 않고 있습니다.\n차량을 픽업(입고)해 완납을 마무리해 주세요.",
        ],

        // ── 국내 딜러(dealer) 수동 발송 1종 — 말소등록증 전달 ──
        // 수신자 = 국내 딜러(직원/해외바이어 아님). 말소증 업로드 지점에서 담당자가 버튼으로 발송.
        // 알림톡은 파일 첨부 불가 → 본문에 만료 서명 링크(#{링크})를 넣어 열람·다운로드하게 한다.
        'erp_deregistration_notice' => [
            'name' => '말소등록증전달',
            'recipient' => 'dealer',
            'vars' => ['차량번호', '링크'],
            'title' => '',
            'body' => "[말소등록증 발급 안내] #{차량번호}\n\n구매하신 차량의 자동차 말소등록증이 발급되었습니다.\n\n▶ 차량번호: #{차량번호}\n\n아래 링크에서 말소등록증을 확인·다운로드하실 수 있습니다.\n#{링크}\n\n(보안을 위해 링크는 3일 후 만료됩니다.)",
        ],

        // ── 월배치 정산지급 승인 사다리 3종 (jin 2026-07-07) ──
        // 수신자는 트리거 단계에서 rank 해석: 요청=다음 계단 승인자(업무관리자→대표) / 결과=제출자.
        'erp_payout_request' => [
            'name' => '정산지급 승인요청',
            'recipient' => '승인자',
            'vars' => ['귀속월', '건수', '총액', '회사이익', '제출자'],
            'title' => '',
            'body' => "[정산 지급 승인 요청]\n\n승인이 필요한 월배치 정산이 도착했습니다.\n아래 버튼으로 내역 확인 후 즉시 승인/반려하실 수 있습니다.",
            // BizM 등록용 버튼(발송 시 url 에 배치·승인자 서명 링크 주입). type=WL(웹링크), url=${URL} 변수.
            'button' => [
                ['name' => '승인/반려 바로가기', 'type' => 'WL', 'url_mobile' => '${URL}', 'url_pc' => '${URL}'],
            ],
        ],
        'erp_payout_done' => [
            'name' => '정산지급 승인완료',
            'recipient' => '제출자',
            'vars' => ['귀속월', '건수', '총액'],
            'title' => '',
            'body' => "[정산 지급 승인 완료]\n\n제출하신 월배치 정산이 최종 승인되어 지급 처리되었습니다.\n정산에서 확인하실 수 있습니다.",
        ],
        'erp_payout_rejected' => [
            'name' => '정산지급 반려',
            'recipient' => '제출자',
            'vars' => ['귀속월', '건수', '사유'],
            'title' => '',
            'body' => "[정산 지급 반려]\n\n제출하신 월배치 정산이 반려되었습니다.\n내용을 확인하고 정정 후 다시 제출해 주세요.",
        ],
    ];

    /**
     * 🟦 아이템리스트형 카드 구조 (9종). ITEMLIST[code] 존재 = 아이템리스트형.
     *   header(카드 헤더) · highlight(강조 타이틀/설명) · items(항목 title/description 목록) · summary(요약).
     *   description·highlight 의 `#{변수}` 는 발송 시 치환. BizM 승인 등록본(회사폴더 upload_erp_*_아이템리스트.xlsx)과 일치.
     */
    public const ITEMLIST = [
        'erp_daily_summary' => [
            'header' => '일일 업무 현황',
            'highlight' => ['title' => '#{매출액}', 'description' => '이번 달 매출 · #{판매건수}대'],
            'items' => [
                ['title' => '선적전 미수', 'description' => '#{선적전건수}건 · #{선적전금액}'],
                ['title' => '선적후 미수', 'description' => '#{선적후건수}건 · #{선적후금액}'],
            ],
            'summary' => ['title' => '미수 합계', 'description' => '#{미수합계}'],
        ],
        'erp_weekly_summary' => [
            'header' => '주간 업무 현황',
            'highlight' => ['title' => '#{매출액}', 'description' => '이번 주 매출 · #{판매건수}대'],
            'items' => [
                ['title' => '선적전 미수', 'description' => '#{선적전건수}건 · #{선적전금액}'],
                ['title' => '선적후 미수', 'description' => '#{선적후건수}건 · #{선적후금액}'],
            ],
        ],
        'erp_monthly_closing' => [
            'header' => '월 결산 보고',
            'highlight' => ['title' => '#{회사이익}', 'description' => '#{대상월} 회사 이익'],
            'items' => [
                ['title' => '총매출', 'description' => '#{총매출}'],
                ['title' => '총마진', 'description' => '#{총마진}'],
                ['title' => '지급 총액', 'description' => '#{지급총액}'],
            ],
        ],
        'erp_vehicle_new' => [
            'header' => '신규 차량 등록',
            'highlight' => ['title' => '#{차량번호}', 'description' => '등록 처리 필요'],
            'items' => [
                ['title' => '바이어', 'description' => '#{바이어}'],
                ['title' => '매입가', 'description' => '#{매입가}'],
            ],
        ],
        'erp_purchase_unpaid' => [
            'header' => '매입 미지급 안내',
            'highlight' => ['title' => '#{총액}', 'description' => '미지급 총액'],
            'items' => [
                ['title' => '대상 건수', 'description' => '#{건수}건'],
                ['title' => '미지급 총액', 'description' => '#{총액}'],
            ],
        ],
        'erp_pickup_reminder' => [
            'header' => '차량 픽업 필요',
            'highlight' => ['title' => '#{차량번호}', 'description' => '#{경과일}일 경과 · 미완납'],
            'items' => [
                ['title' => '구입처', 'description' => '#{구입처}'],
                ['title' => '미지급 금액', 'description' => '#{미지급금액}'],
                ['title' => '매입일', 'description' => '#{매입일}'],
            ],
        ],
        'erp_payout_request' => [
            'header' => '정산 지급 승인요청',
            'highlight' => ['title' => '#{총액}', 'description' => '#{귀속월} 지급 총액'],
            'items' => [
                ['title' => '건수', 'description' => '#{건수}건'],
                ['title' => '회사이익', 'description' => '#{회사이익}'],
                ['title' => '제출', 'description' => '#{제출자}'],
            ],
        ],
        'erp_payout_done' => [
            'header' => '정산 지급 완료',
            'highlight' => ['title' => '#{총액}', 'description' => '#{귀속월} 지급 완료'],
            'items' => [
                ['title' => '귀속월', 'description' => '#{귀속월}'],
                ['title' => '건수', 'description' => '#{건수}건'],
            ],
        ],
        'erp_payout_rejected' => [
            'header' => '정산 지급 반려',
            'highlight' => ['title' => '#{귀속월}', 'description' => '정산 반려됨'],
            'items' => [
                ['title' => '건수', 'description' => '#{건수}건'],
                ['title' => '반려 사유', 'description' => '#{사유}'],
            ],
        ],
    ];

    /**
     * 발송 시점 설명 (2026-07-23) — 알림톡 안내 화면용. code => 언제 발송되나(사람 설명).
     * 값·수신자·시점 변경은 재승인 불필요(고정문구 아님) — 여기서 관리.
     */
    public const WHEN = [
        'erp_daily_summary' => '매일 09:00 (평일) — 대표 일일 매출·미수 요약',
        'erp_weekly_summary' => '매주 금요일 18:00 — 대표 주간 요약',
        'erp_monthly_closing' => '매월 1일 09:00 — 전월 결산 요약',
        'erp_vehicle_new' => 'board 경유 신규 차량 등록 시',
        'erp_purchase_unpaid' => '매일 09:00 (평일) — 매입 미지급 있으면',
        'erp_sale_unpaid' => '매일 09:00 (평일) — 판매 미입금 있으면 (결제대기 10일 유예 제외)',
        'erp_settle_pending' => '거래완료로 새 정산 생성 시',
        'erp_eta_balance_due' => '매일 09:00 (평일) — 도착 7일 전 & 잔금 미완납',
        'erp_shipping_due' => '매일 09:00 (평일) — 선적 5일 전 & 미완납',
        'erp_dealer_balance_due' => '매일 09:00 (평일) — 매매상 잔금 기한 임박 (karaba)',
        'erp_deposit_cash_due' => '매일 09:00 (평일) — 보증금 매입 도장 D+5~10 & 바이어 입금 기준 미달',
        'erp_deposit_cash_overdue' => '매일 09:00 (평일) — 보증금 매입 도장 D+10 초과 & 미달',
        'erp_deposit_funding_request' => '보증금 선지급 기안 시(→관리) / 관리 승인 시(→재무)',
        'erp_deposit_funding_done' => '보증금 선지급 재무 확정 시(→기안자)',
        'erp_deposit_funding_rejected' => '보증금 선지급 관리 반려·재무 거부 시(→기안자)',
        'erp_pickup_reminder' => '매일 09:00 (평일) — 매입일 +2일 & 매입 미완납',
        'erp_deregistration_notice' => '말소등록증 업로드 후 담당자가 수동 발송',
        'erp_payout_request' => '월배치 정산 지급 제출·전진 시 (→다음 계단 승인자)',
        'erp_payout_done' => '월배치 정산 지급 최종 승인 시 (→제출자)',
        'erp_payout_rejected' => '월배치 정산 지급 반려 시 (→제출자)',
    ];

    /**
     * 알림톡 안내 카탈로그 (2026-07-23) — 화면용. 각 템플릿의 이름·수신자·발송시점·본문.
     *
     * @return array<int, array{code:string, name:string, recipient:string, when:string, body:string}>
     */
    public static function catalog(): array
    {
        $rows = [];
        foreach (self::TEMPLATES as $code => $t) {
            $rows[] = [
                'code' => $code,
                'name' => $t['name'] ?? $code,
                'recipient' => $t['recipient'] ?? '-',
                'when' => self::WHEN[$code] ?? '-',
                'body' => $t['body'] ?? '',
            ];
        }

        return $rows;
    }

    /** 본문 렌더 — `#{변수}` 치환. 없는 코드면 빈 문자열. */
    public static function render(string $code, array $vars = []): string
    {
        return self::substitute(self::TEMPLATES[$code]['body'] ?? '', $vars);
    }

    /** 강조 타이틀 렌더 — 강조표기형(title 존재)만. 기본형이면 빈 문자열. (1차 롤아웃 전부 기본형이라 현재 미사용) */
    public static function renderTitle(string $code, array $vars = []): string
    {
        return self::substitute(self::TEMPLATES[$code]['title'] ?? '', $vars);
    }

    /** 아이템리스트형 여부. */
    public static function hasItemList(string $code): bool
    {
        return isset(self::ITEMLIST[$code]);
    }

    /**
     * 아이템리스트 발송 payload — `#{변수}` 치환 후 BizM v2 send 형식으로 반환.
     *   ['header'=>..., 'items'=>['item'=>['list'=>[...], 'summary'=>...], 'itemHighlight'=>...]]
     *   아이템리스트형 아니면 null.
     */
    public static function itemListPayload(string $code, array $vars = []): ?array
    {
        $il = self::ITEMLIST[$code] ?? null;
        if ($il === null) {
            return null;
        }
        $sub = fn (string $t): string => self::substitute($t, $vars);

        $item = [
            'list' => array_map(
                fn (array $it): array => ['title' => $sub($it['title']), 'description' => $sub($it['description'])],
                $il['items'],
            ),
        ];
        if (isset($il['summary'])) {
            $item['summary'] = ['title' => $sub($il['summary']['title']), 'description' => $sub($il['summary']['description'])];
        }

        $payload = ['header' => $sub($il['header']), 'items' => ['item' => $item]];
        if (isset($il['highlight'])) {
            $payload['items']['itemHighlight'] = ['title' => $sub($il['highlight']['title']), 'description' => $sub($il['highlight']['description'])];
        }

        return $payload;
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
