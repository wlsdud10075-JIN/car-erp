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

        /*
         * 보증금 매입 선지급 승인 3종(request/done/rejected) 제거 — 2026-07-30 (jin).
         *
         * 발송 트리거였던 승인 사다리(InterVehicleTransferService::applyPurchaseFunding →
         * approve → confirmPurchaseFundingByFinance)가 2026-07-29 에 통째로 폐기되면서
         * 보낼 이벤트 자체가 사라졌다. 본문이 "ERP 승인/이체 화면에서", "기안하신" 으로
         * 그 흐름을 직접 가리켜 다른 용도로 재활용할 수도 없다(문구 변경 = BizM 재승인).
         *
         * ⚠️ 독촉 2종(erp_deposit_cash_due/overdue)은 그대로 살아 있다 — 본문이 승인 사다리를
         *   전제하지 않고 사실만 서술해서 재승인 없이 계속 쓴다. 트리거만 차량 매입 탭
         *   「보증금으로 매입」 체크박스로 옮겼다(Vehicle::saving 이 deposit_purchase_at 도장).
         */

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

        // ── 국내 딜러(dealer) 수동 발송 2종째 — 매입대금 입금완료 (jin 2026-08-12) ──
        // 🧩 **계약금·매입잔금이 이 템플릿 하나를 공유한다** — 종류는 `#{구분}` 변수로 실린다.
        //    신호마다 등록하면 회사 수 × 신호 수만큼 승인을 받아야 하고 문구 수정 때마다 전부 재승인이다
        //    (`erp_board_request` 가 3종을 하나로 묶은 것과 같은 판단 — SKILLS §8 #54).
        //    ⚠️ 대신 **본문 조립에서 종류별 차이를 처리할 책임**이 생긴다. 지금은 금액만 다르지만,
        //       새 종류를 얹을 땐 "이 문장이 그 신호에도 참인가"를 항목별로 확인할 것.
        //
        // 🧭 **방향을 못 박은 이유** — 이건 "우리가 보냈다" 는 통지다. 계좌번호가 그냥 적혀 있으면
        //    받는 분이 "여기로 보내라" 로 읽는다(#54 의 그 사고). 그래서 **「입금받으신 계좌」** 로 쓴다.
        // 🔒 **계좌는 뒤 4자리만** 나간다(`DocValue` 아님 — 조립 지점에서 마스킹). 전화번호를 잘못 적으면
        //    남의 계좌번호가 통째로 나가므로, 본인 확인에 필요한 만큼만 싣는다.
        // ⚠️ 매입처 계좌는 운영 실측 243대 중 67대만 채워져 있다 — 없으면 `-` 가 나간다(발송은 막지 않는다).
        'erp_purchase_paid' => [
            'name' => '매입대금입금완료',
            'recipient' => 'dealer',
            'vars' => ['차량번호', '구분', '금액', '계좌', '입금자명'],
            'title' => '',
            'body' => "[매입대금 입금 안내] #{차량번호}\n\n차량 매입 거래처(딜러)로 등록된 분께 발송되는 입금 통지입니다.\n\n▶ 차량번호: #{차량번호}\n▶ 구분: #{구분}\n▶ 입금 금액: #{금액}\n▶ 입금받으신 계좌: #{계좌}\n▶ 입금자명: #{입금자명}\n\n위 금액을 입금해 드렸습니다. 입금받으신 계좌에서 내역을 확인해 주세요.\n본 안내는 차량관리 ERP에서 담당자가 입금 처리 후 발송합니다.",
        ],

        // ── 대표 주간 자금/손익 보고 1종 (jin 2026-07-23) — 통장현금+재고·미수·미지급+손익 ──
        // 수신자 = 대표(admin). 자본·손익 기밀이라 admin 전용. 최신 CashSnapshot 기준.
        // ⚠️ 코드명은 weekly 지만 **주간·월말 공용**(2026-07-27) — 링크 보고서가 붙으면서 발송 주기만 다르고
        //    화면·템플릿은 하나다. 코드명을 바꾸면 BizM 재등록이라 그대로 둔다.
        'erp_capital_weekly' => [
            'name' => '자금보고',
            'recipient' => 'admin',
            'vars' => ['기준일', '통장현금', '재고', '미수', '미지급', '굴리는자금', '손익'],
            'title' => '',
            // ⚠️ 2026-07-30 BizM 반려 대응 — 사유 "수신 대상 불명확". 카카오는 본문만 읽고
            //   수신자가 누구인지·어떤 액션으로 발송되는지 판별할 수 있어야 한다. 구 문구
            //   ("회사 자금 현황입니다")는 업무 명사도 시스템명도 없어 재테크 푸시로 읽혔다.
            //   통과한 14종의 공통 패턴 = 대괄호 업무 제목 + 발송 근거 + "ERP" 명시.
            // ⚠️ "대표님" 을 본문에 박은 건 수신 대상을 못 박아 심사를 통과시키기 위함(jin 2026-07-30).
            //   수신자는 DEFAULT_ROLES=['admin'] 로 실제 대표 전용이라 사실과 일치한다.
            //   나중에 수신 역할을 넓히면 이 고정문구가 거짓이 되므로 **BizM 재승인**이 필요하다.
            'body' => "[사내 업무용] 자금 현황 보고 #{기준일}\n\n사내 차량관리 ERP에서 자금 보고 수신자로 지정된 대표님께 발송되는 내부 경영 보고입니다.\n\n아래 버튼 또는 사내 업무 시스템(ERP)에서 자세한 내역을 확인해 주세요.",
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
        // ── board 요청 신호 1종 (jin 2026-08-11) ──
        // board 영업이 [계약금]·[매입잔금]·[판매대금확인] 을 보내면 즉시 발송. 수신자는 **시각 규칙**으로
        // 갈린다(근무시간엔 담당자, 그 밖엔 대표) — AlimtalkRecipients::forTimeRules().
        // ⚠️ 요약(summary)을 두지 않았다. 요약 description 은 **금액 표기만** 허용이라(K140),
        //    금액이 없는 판매대금확인에서 '0원' 을 찍거나 발송이 통째로 반려된다.
        'erp_board_request' => [
            'name' => 'board 요청접수',
            'recipient' => '시각 규칙',
            'vars' => ['건수', '구분', '차량', '금액', '요청자', '요청내역'],
            'title' => '',
            // ⚠️ **수신 대상을 본문에 못 박는다.** 카카오는 본문만 읽고 "이게 누구에게 왜 가는지"를
            //    판별할 수 있어야 승인한다 — 2026-07-30 자금보고가 "수신 대상 불명확"으로 반려됐고,
            //    통과한 문구의 공통 패턴이 [대괄호 업무 제목] + 발송 근거 + 수신자 명시 + "ERP" 였다.
            //    수신자가 시각에 따라 갈리므로(담당자/대표) 특정 직함 대신 "입금 처리 담당자로 지정된
            //    임직원"으로 적는다 — 라우팅을 바꿔도 이 문장이 거짓이 되지 않는다.
            'body' => "[사내 업무용] board 요청 접수 #{건수}건\n\n사내 차량관리 ERP에서 입금 처리 담당자로 지정된 임직원에게 발송되는 내부 업무 알림입니다.\n\n#{요청내역}\n\n사내 업무 시스템(ERP) 차량관리에서 확인 후 처리해 주세요.",
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
        // ⚠️ title 은 6자·highlight description 은 19자·item description 은 20자(자동컷) 상한.
        //    summary 는 일부러 없다(위 TEMPLATES 주석 — K140).
        'erp_board_request' => [
            'header' => 'board 요청 접수',
            'highlight' => ['title' => '#{건수}건', 'description' => '#{구분}'],
            'items' => [
                ['title' => '차량', 'description' => '#{차량}'],
                ['title' => '금액', 'description' => '#{금액}'],
                ['title' => '요청', 'description' => '#{요청자}'],
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
        'erp_capital_weekly' => [
            'header' => '사내 자금 현황 보고',   // 16자 제한. 반려 대응으로 '사내' 명시(2026-07-30)
            // ⚠️ highlight description 은 **19자 제한** — '굴리는 총 자금 · #{기준일}'(치환 후 21자)로
            //    보냈다가 K135 반려(2026-07-31 실측). 승인 등록본(upload_erp_헤이맨_자금보고_재등록.xlsx
            //    P6)이 띄어쓰기를 뺀 '굴리는총자금·#{기준일}'(17자)이라 여기도 등록본과 글자단위로 맞춘다.
            'highlight' => ['title' => '#{굴리는자금}', 'description' => '굴리는총자금·#{기준일}'],
            'items' => [
                ['title' => '통장 현금', 'description' => '#{통장현금}'],
                ['title' => '재고', 'description' => '#{재고}'],
                ['title' => '미수', 'description' => '#{미수}'],
                ['title' => '미지급', 'description' => '#{미지급}'],
            ],
            // ⚠️ title 은 **6자 제한** — '원금 대비 손익'(공백 포함 8자)로 보냈다가 K208 반려(2026-07-31 실측).
            //    jin 이 BizM 등록본에서 띄어쓰기를 뺀 '원금대비손익'(6자)으로 맞춰, 여기도 동일하게 유지한다.
            'summary' => ['title' => '원금대비손익', 'description' => '#{손익}'],
        ],
    ];

    /**
     * 발송 시점 설명 (2026-07-23) — 알림톡 안내 화면용. code => 언제 발송되나(사람 설명).
     * 값·수신자·시점 변경은 재승인 불필요(고정문구 아님) — 여기서 관리.
     */
    public const WHEN = [
        'erp_daily_summary' => '매일 09:00 (평일) — 대표 일일 매출·미수 요약',
        'erp_weekly_summary' => '매주 금요일 18:00 — 대표 주간 요약',
        'erp_capital_weekly' => '매주 월요일 09:00 — 대표 주간 자금/손익 보고 (통장현금·재고·미수·미지급·손익)',
        'erp_monthly_closing' => '월배치 정산이 최종 승인된 때 (2026-07-31 변경 — 구: 익월 첫 영업일. 정산 확정 전에 나가 마진·지급이 과소보고됐다)',
        'erp_vehicle_new' => 'board 경유 신규 차량 등록 시',
        'erp_purchase_unpaid' => '매일 09:00 (평일) — 매입 미지급 있으면',
        'erp_sale_unpaid' => '매일 09:00 (평일) — 판매 미입금 있으면 (결제대기 10일 유예 제외)',
        'erp_settle_pending' => '거래완료로 새 정산 생성 시',
        'erp_eta_balance_due' => '매일 09:00 (평일) — 도착 7일 전 & 잔금 미완납',
        'erp_shipping_due' => '매일 09:00 (평일) — 선적 5일 전 & 미완납',
        'erp_dealer_balance_due' => '매일 09:00 (평일) — 매매상 잔금 기한 임박 (karaba)',
        'erp_deposit_cash_due' => '매일 09:00 (평일) — 매입 탭 「보증금으로 매입」 체크 후 5~10일 & 바이어 입금 기준 미달',
        'erp_deposit_cash_overdue' => '매일 09:00 (평일) — 「보증금으로 매입」 체크 후 10일 초과 & 미달 (독촉 대상에선 제외)',
        'erp_pickup_reminder' => '매일 09:00 (평일) — 매입일 +2일 & 매입 미완납',
        'erp_deregistration_notice' => '말소등록증 업로드 후 담당자가 수동 발송',
        'erp_purchase_paid' => '계약금·매입잔금 지급 후 매입 탭에서 담당자가 수동 발송 (딜러 연락처 필요)',
        'erp_board_request' => 'board 영업이 계약금·매입잔금·판매대금확인을 보낸 즉시 (수신자는 시각 규칙으로 갈림 — 근무시간엔 담당자, 그 밖엔 대표)',
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
     * 아이템리스트 description 상한 — 초과분은 잘라서 보낸다.
     *
     * 카카오 규격 초과 시 BizM 이 발송 자체를 반려한다(K137:ExceedMaxItemDescriptionLength /
     * K140:InvalidItemSummaryDescription). 2026-07-28 운영 실측 = 20자 성공 / 25자 실패
     * (erp_pickup_reminder 61건 반려 — 구입처에 담당자·전화번호가 붙어 25~30자였다).
     * 값은 차량 데이터라 길이를 예측할 수 없으므로 조립 지점에서 일괄 컷한다.
     */
    private const ITEM_DESC_MAX = 20;

    /** $vars 안에서 "카드에만 쓸 값" 을 담는 키. 본문 렌더는 이 키를 무시한다(substitute 가 배열을 건너뜀). */
    public const CARD_VARS_KEY = '__card';

    /**
     * 카드(아이템리스트) description 전용 금액 축약 — 20자 컷 안에 다른 정보와 같이 들어가야 한다.
     *
     * ⚠️ `Money::krwShort()` 를 쓰면 안 된다. 그건 `4억 8,334만`(9자)라 앞에 건수·뒤에 % 를 붙이면
     *    22자가 되어 잘린다. 여기서는 소수 2자리 억(`4.83억`, 6자)으로 줄인다.
     *    1억 미만은 `0.05억` 이 읽히지 않으므로 만 단위로, 0 은 `0.00억` 대신 `0원`.
     * 본문(msg)은 길이 여유가 있으므로 이걸 쓰지 말고 정확한 원 단위를 보낼 것.
     */
    public static function cardMoney(int $amount): string
    {
        if ($amount === 0) {
            return '0원';
        }
        if (abs($amount) < 100_000_000) {
            return number_format((int) round($amount / 10_000)).'만';
        }

        $eok = $amount / 1e8;

        return (abs($eok) >= 10 ? number_format($eok, 1) : number_format($eok, 2)).'억';
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
        // 카드 전용 값 오버라이드 — 본문(msg)엔 정확한 금액을, 카드엔 축약형을 넣기 위한 장치(jin 2026-08-06).
        //   카드 description 은 20자 컷이라 본문과 같은 문자열을 쓰면 뒤가 잘린다. 등록본의 고정 문구
        //   (`#{선적전건수}건 · #{선적전금액}`)는 그대로이고 **값만** 달라지므로 BizM 재등록은 필요 없다.
        $vars = array_merge($vars, is_array($vars[self::CARD_VARS_KEY] ?? null) ? $vars[self::CARD_VARS_KEY] : []);

        $sub = fn (string $t): string => self::substitute($t, $vars);
        $desc = fn (string $t): string => mb_substr($sub($t), 0, self::ITEM_DESC_MAX);

        $item = [
            'list' => array_map(
                fn (array $it): array => ['title' => $sub($it['title']), 'description' => $desc($it['description'])],
                $il['items'],
            ),
        ];
        if (isset($il['summary'])) {
            $item['summary'] = ['title' => $sub($il['summary']['title']), 'description' => $desc($il['summary']['description'])];
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
            if (is_array($value)) {
                continue;   // 카드 전용 오버라이드(CARD_VARS_KEY) 등 — 치환 대상 아님
            }
            $text = str_replace('#{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }
}
