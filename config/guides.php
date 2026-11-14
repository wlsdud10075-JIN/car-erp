<?php

/*
 * 업무 가이드(/guides) — 씬 워크스루 목록의 단일 출처 (jin 2026-11-13, 기획 = docs/design/scene-walkthrough.md §4-B·§6).
 *
 * 씬 하나 = 기능 하나를 처음부터 끝까지 실제로 눌러 본 한 장. 본문은 `resources/guides/{slug}/index.html`(+ 같은 폴더 png).
 * 본문 파일이 없는 씬은 목록에 「준비 중」으로 나오고 열리지 않는다 — 상태를 여기 적지 않는다(파일이 곧 상태).
 *
 * 🚫 시스템관리자 일(알림톡 로그·관제센터·기능설정)은 넣지 않는다 — 목록에도, 씬 본문 단계에도(jin 11-13).
 * 🔗 slug 규칙 = 영문 소문자 kebab, 「대상-동작」. board 와 같은 규칙을 쓴다(서로 slug 로 가리킨다).
 */
return [
    'path' => resource_path('guides'),

    // 갱신 뒤 이 날수 동안 「새로 올라옴」으로 보인다.
    'new_days' => 14,

    // 묶음(업무 흐름 순).
    'groups' => ['purchase' => '매입', 'sale' => '판매·입금', 'export' => '수출통관', 'settlement' => '채권·정산', 'manage' => '관리'],

    // 「처음이라면 — 한 바퀴」 순서(같은 차 한 대로 매입부터 정산까지).
    'lap' => ['vehicle-register', 'purchase-payment', 'sale-register', 'sale-payment', 'export-tab', 'monthly-payout'],

    // 🚫 board 업무 가이드로 가는 링크는 두지 않는다(jin 2026-11-14 «ERP 랑 BOARD 는 서로 업무 알 필요 없다 — 각자 업무가이드»).
    //    ERP 계정이 있어도 board 계정이 없는 사람이 있어 링크를 타면 board 로그인에서 막혔다. board 쪽도 ERP 링크를 없앤다.

    // 🇬🇧 영어 화면(언어 = en)에서 쓰는 제목·요약 [slug => [제목, 한 줄 요약]] (jin 2026-11-13 «영어로 바꿨을 때 적용되게»).
    //    본문 영어판은 `resources/guides/{slug}/index.en.html` — 없으면 한국어 본문을 「Korean only」 표시와 함께 보여준다.
    //    씬을 추가하면 여기도 같이 넣는다(가드 = GuidesPageTest::test_every_scene_has_an_english_title).
    'scenes_en' => [
        'vehicle-register' => ['Register a vehicle', 'Pull the vehicle record, fill in the purchase tab and save'],
        'board-purchase-sync' => ['Board purchase confirmed → vehicle created', 'Confirming a purchase in Board creates the vehicle in the ERP'],
        'board-payment-request' => ['Handle Board deposit / balance requests', 'Confirm or reject a payment request from Board in the vehicle panel'],
        'purchase-payment' => ['Pay the dealer and send the payment notice', 'Enter and confirm the deposit and balance, then notify the dealer by KakaoTalk'],
        'deregistration-notice' => ['Deregistration and sending the certificate', 'Upload the certificate, send it to the dealer and request the tax invoice'],
        'purchase-cancel' => ['Cancel a purchase and close the shortfall', 'Cancel the purchase, receive the penalty and close what is left'],
        'sale-register' => ['Register a sale', 'Enter buyer, currency, exchange rate and price, then issue the invoice'],
        'sale-payment' => ['Record sale receipts', 'Enter and confirm receipts; the unpaid ratio and stage follow'],
        'board-sale-payment-check' => ['Board sale-payment check request', 'Handle a receipt check requested from Board'],
        'buyer-account' => ['Buyer account status', 'Split cash received from a buyer across vehicles'],
        'savings' => ['Earn and use buyer credit', 'Build up credit and apply it to the next vehicle'],
        'buyer-undecided' => ['Revert to "buyer undecided"', 'Undo a sale when the buyer cancels'],
        'sales-contract-esign' => ['E-sign the sales contract', 'Send the signing link and follow the status'],
        'export-tab' => ['Export tab — top to bottom', 'Loading place, declaration, shipping date, ETA, then the customs check'],
        'unpaid-gate-override' => ['When the payment gate blocks you', 'Request an override when shipping is blocked by unpaid balance'],
        'bl-issue' => ['Issue the B/L and close the deal', 'Upload the B/L on a fully paid vehicle to complete the deal'],
        'shipment-fee' => ['Document shipping — EMS / DHL', 'Enter tracking numbers and fees, or upload them in bulk'],
        'documents' => ['Generate documents', 'Create the customs set and shipping documents for several vehicles at once'],
        'bulk-shipping-dates' => ['Bulk-enter shipping date, ETA and numbers', 'Fill dates and numbers for every vehicle on the same vessel'],
        'shipping-requests' => ['Shipping requests', 'Handle B/L requests and change requests from Board shipping groups'],
        'inventory-location' => ['Inventory — location and release date', 'Record where a vehicle is parked and when it left'],
        'receivables' => ['Receivables', 'Record collections and follow the risk level'],
        'monthly-payout' => ['Monthly payout — submit to final close', 'Confirm settlements, submit for approval and pay out'],
        'secondary-settlement' => ['Second settlement', 'Enter actual costs and close; the difference carries to next month'],
        'ledger-readjust' => ['Readjust after closing', 'Add freight to a closed vehicle and collect the new balance'],
        'leaver-domestic-payout' => ['Leaver payout · domestic staff payout', 'Two payouts that are approved separately'],
        'bank-balance' => ['Bank balances and the cash report', 'Enter closing balances per account for the cash report'],
        'buyer-claim' => ['Handle a claim', 'Accept or reject a buyer claim'],
        'kakao-todo' => ['KakaoTalk automation — my to-dos', 'Read chat rooms and turn messages into to-dos'],
    ],

    // [slug => [묶음, 제목, 한 줄 요약, 누가 쓰나[], 찾는 말]]
    'scenes' => [
        'vehicle-register' => ['purchase', '차량 등록', '원부조회로 차량 정보를 불러오고 매입 탭을 채워 저장합니다', ['관리'], '신규 등록 원부조회 NICE 차대번호'],
        'board-purchase-sync' => ['purchase', 'board 매입 확정 → 차량 자동 생성', 'board에서 매입을 확정하면 ERP에 차량이 만들어집니다', ['관리'], '매입 연동 송금인 첨부'],
        'board-payment-request' => ['purchase', 'board 매입잔금·계약금 요청 처리', 'board가 보낸 지급 요청을 차량 패널에서 확인하거나 거절합니다', ['재무', '관리'], '입금요청 계약금 매입잔금'],
        'purchase-payment' => ['purchase', '매입 지급과 딜러 입금완료 알림', '계약금·잔금을 넣고 확정한 뒤 딜러에게 입금완료 알림톡을 보냅니다', ['재무'], '딜러알림 이체확인증 잔금 계약금'],
        'deregistration-notice' => ['purchase', '말소와 말소등록증 전달', '말소증을 올리고 딜러에게 말소등록증·세금계산서 요청을 보냅니다', ['수출통관', '재무'], '말소 세금계산서'],
        'purchase-cancel' => ['purchase', '매입취소와 미수 마감', '매입을 취소하고 위약금을 받은 뒤 미수를 마감합니다', ['관리', '재무'], '위약금 취소 마감'],
        'sale-register' => ['sale', '판매 등록', '바이어·통화·환율·판매가를 넣고 인보이스를 뽑습니다', ['관리'], '판매가 환율 바이어 인보이스 계약서'],
        'sale-payment' => ['sale', '판매 입금', '입금을 넣고 확정하면 미수율과 진행상태가 바뀝니다', ['재무'], '잔금 중도금 완납 미수'],
        'board-sale-payment-check' => ['sale', 'board 판매대금확인 요청', 'board가 보낸 입금 확인 요청을 처리합니다', ['재무'], '판매대금확인'],
        'buyer-account' => ['sale', '바이어 정산현황', '바이어가 보낸 현금을 차량별 잔금으로 나눕니다', ['재무'], '현금 입금 배분'],
        'savings' => ['sale', '적립금 적립과 사용', '적립금을 쌓고 다음 차 잔금에 씁니다', ['재무'], '적립금 크레딧'],
        'buyer-undecided' => ['sale', '바이어 미정으로 되돌리기', '바이어가 구매를 취소했을 때 판매를 무릅니다', ['관리'], '판매 취소 무르기'],
        'sales-contract-esign' => ['sale', '판매계약서 전자서명', '서명 링크를 보내고 서명 상태를 봅니다', ['관리'], '전자서명 계약서'],
        'export-tab' => ['export', '수출 탭 — 위에서 아래로', '반입지·신고서·선적일·ETA·통관 체크 순서로 채웁니다', ['수출통관'], '선적중 선적완료 통관중 통관완료 운항'],
        'unpaid-gate-override' => ['export', '입금률 게이트에 막혔을 때', '선적이 막히면 미입금 우회 승인을 요청합니다', ['수출통관', '관리'], '락 우회 승인 선적이 막힌다'],
        'bl-issue' => ['export', 'B/L 발급과 거래완료', '완납된 차에 B/L을 올리면 거래가 끝납니다', ['수출통관'], '선하증권 비엘'],
        'shipment-fee' => ['export', '서류 발송 — EMS·DHL', '발송 번호와 금액을 넣거나 엑셀로 한 번에 올립니다', ['수출통관'], '우체국 발송비'],
        'documents' => ['export', '서류 만들기', '통관 SET과 선적 서류를 여러 대 한 번에 만듭니다', ['수출통관'], '통관 SET 인보이스 패킹 말소증'],
        'bulk-shipping-dates' => ['export', '선적일·ETA·번호 일괄 기입', '같은 배에 탄 차들의 날짜와 번호를 한 번에 넣습니다', ['수출통관'], '일괄 기입 B/L 번호'],
        'shipping-requests' => ['export', '선적요청', 'board 선적 묶음에서 B/L 요청과 변경요청을 처리합니다', ['수출통관'], '컨테이너 묶음'],
        'inventory-location' => ['export', '재고관리 — 보관위치·출고일', '차가 어디 서 있는지와 나간 날을 적습니다', ['수출통관', '관리'], '야드 홈플 출고'],
        'receivables' => ['settlement', '채권관리', '회수 기록을 남기고 위험도를 봅니다', ['재무'], '미수 회수 상계'],
        'monthly-payout' => ['settlement', '월정산 — 제출부터 최종 확정까지', '정산을 확정하고 결재를 올려 지급까지 마칩니다', ['재무', '업무관리자'], '결재선 승인 지급 정산 엑셀'],
        'secondary-settlement' => ['settlement', '2차 정산', '명세서 비용을 넣고 마감하면 차액이 다음 달로 넘어갑니다', ['재무'], '명세서 이월 마감'],
        'ledger-readjust' => ['settlement', '마감 뒤 회계 재조정', '마감된 차에 운임비를 넣고 새 미수를 받습니다', ['재무', '관리'], '운임비 재조정'],
        'leaver-domestic-payout' => ['settlement', '퇴사자 정산 · 내수직원 월정산', '따로 결재하는 두 가지 정산입니다', ['업무관리자'], '퇴사 예치금 평가수수료'],
        'bank-balance' => ['settlement', '통장 잔액과 자금 보고', '계좌별 마감 잔액을 넣으면 자금 보고에 반영됩니다', ['재무'], '통장 자금'],
        'buyer-claim' => ['manage', '클레임 처리', '바이어 클레임을 인정하거나 기각합니다', ['관리'], '클레임'],
        'kakao-todo' => ['manage', '카톡 자동화 — 내 카톡 할 일', '카톡방을 읽어 할 일로 정리합니다', ['관리'], '카카오톡 할 일'],
    ],
];
