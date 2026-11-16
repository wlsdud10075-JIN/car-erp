// 씬 점검 2단계 「한 바퀴」 공용 — vehicle-register → purchase-payment → sale-register → sale-payment → export-tab → monthly-payout.
// dev 전용 · master 제외 · 로컬 8001 전용. 정본 = docs/design/scene-walkthrough.md §8.
//
// 🔑 한 바퀴 예시 차는 00가1113 이 아니라 **전용 번호판**이다(초안 결정 — main 확인 필요).
//   - 00가1113 은 촬영 때 거래완료까지 간 차라 1단계 documents 가 그대로 쓰고, 시더 run() 의 PLATES 가드가
//     없으면 멈춘다. 되돌리려면 지급완료 정산·최종 확정 배치·바이어 36 의 현금 배분까지 지워야 해 1단계와 얽힌다.
//   - 전용 바이어(SCENE LOOP TRADING)는 미수 0 이라 매입 등록 락(바이어 미수 게이트)이 안 뜬다.
//     GUIDE SAMPLE TRADING(바이어 36)은 지금 미수가 커서 게이트 창이 뜰 수 있다.
//   - 번호판은 `00가11` 접두어 밖 — documents·bulk-shipping-dates 가 `search=00가11` 로 목록을 띄운다.
//   ⇒ 가이드 문구 중 번호판이 든 것(인보이스 파일명 등)은 PLATE 변수로 맞춘다.
// 시더(GuideSceneFixtureSeeder::resetLoop)가 매 실행 전에 이 차·바이어·컨사이니·월정산 배치를 지운다.
const L = require('./lib');

const PLATE = '00가2001';
const BUYER = 'SCENE LOOP TRADING';
const CONSIGNEE = 'SCENE LOOP CONSIGNEE';
const SALESMAN_LABEL = '김영업';          // 로컬 예시 영업(가상 이름) — 촬영 스크립트와 같은 사람(건당 정산)
const RECEIPT_NOTE = 'SCENE LOOP 판매대금';

/** 그 차 한 줄(삭제 안 된 것). 없으면 null. 🚫 실명 칼럼 금지. */
function vehicle() {
  return L.dbOne(
    `select id, progress_status_cache p, purchase_price, selling_fee, purchase_date, purchase_from, cost_deregistration,
            buyer_id, buyer_undecided, salesman_id, sale_price, transport_fee, currency, exchange_rate, sale_date,
            sale_unpaid_amount_krw_cache u, is_deregistered, deregistration_document, nice_reg_owner_rrn is not null has_rrn,
            bl_loading_location, forwarding_company_id, shipping_method, port_of_loading, incoterms, export_consignee_id, bl_consignee_id,
            export_declaration_number, export_declaration_document, shipping_date, eta_date, vessel_name, is_export_cleared
       from vehicles where vehicle_number = ? and deleted_at is null`, [PLATE]);
}
function buyerRow() { return L.dbOne('select id from buyers where name = ? and deleted_at is null', [BUYER]); }
const ymdOf = (iso) => iso.replace(/-/g, '');

/** 날짜칸(data-date · flatpickr) — 8자리로 치고 Tab(가이드 「20261113처럼 숫자 8자리로 쳐도」). */
async function typeDate(page, selector, iso) {
  const d = page.locator(selector).first();
  await d.click();
  await d.press('Control+A');
  await d.press('Backspace');
  await d.pressSequentially(ymdOf(iso));
  await page.keyboard.press('Tab');
  await page.waitForTimeout(500);
  const v = await d.inputValue();
  if (v !== iso) throw new Error(`날짜칸 ${selector}: 8자리 입력 뒤 ${iso} 로 안 바뀜(${v})`);
}
/** 금액칸(data-money) — 콤마 포매터가 붙으므로 fill 대신 한 글자씩. */
async function typeMoney(page, selector, digits) {
  const m = page.locator(selector).first();
  await m.click();
  await m.press('Control+A');
  await m.press('Backspace');
  await m.pressSequentially(String(digits));
}

/**
 * 「수정 저장」 → (뜨면) 확인 창 → 「차량 정보가 수정됐습니다.」.
 * expectTitle 이 있으면 그 확인 창 제목(예: 「매입 정보 확인」)이 떠야 한다(관리 계정). 없으면 확인 창 없이 저장돼야 할 계정(재무·수출통관).
 * 그 밖의 창(서류 누락 확인 「그대로 저장 …」 · 미래 날짜 「맞습니다, 저장」)은 촬영 스크립트처럼 넘긴다.
 */
async function saveEdit(page, stepName, { expectTitle = null } = {}) {
  const action = async () => {
    await page.getByRole('button', { name: '수정 저장' }).last().click();
    await page.waitForTimeout(1200);
    if (expectTitle) {
      await L.expectText(page, expectTitle, `${stepName} 확인 창 제목`);
      await L.expectText(page, '돌아가서 수정', `${stepName} [돌아가서 수정]`);
      await page.getByRole('button', { name: '확인 후 저장' }).last().click();
    }
    for (let i = 0; i < 3; i++) {
      await page.waitForTimeout(1500);
      const x = page.locator('button:visible', { hasText: /맞습니다, 저장|그대로 저장/ });
      if (await x.count()) await x.first().click(); else break;
    }
  };
  await L.expectAfter(page, action, '차량 정보가 수정됐습니다.', `${stepName} 저장 토스트`, { timeout: 45000 });
  await L.assertNoViolations(page);
}

/** 덮인 버튼(알람 팝업 등) 대비 — 보통 클릭, 안 되면 DOM click(가드 리스너는 그대로 탄다). */
async function clickSafe(locator) {
  try { await locator.click({ timeout: 8000 }); } catch { await locator.evaluate((e) => e.click()); }
}

/** 업로드용 작은 PDF — cap/ 의 sample-*.pdf 는 레포에 없다(촬영 scratchpad 에만 있었다). */
function pdf(name) {
  const body = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
    + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';
  return { name, mimeType: 'application/pdf', buffer: Buffer.from(body, 'latin1') };
}

// 체인 사전 조건: 각 씬의 첫 단계가 앞 씬 결과를 DB 로 보고, 없으면 r.skip(사유) — 원인은 앞 씬 한 곳에서만 실패로 보고된다.
// 가이드에 있지만 안 보는 단계는 단계로 기록하지 않는다(ok:null 이 하나라도 있으면 run.js 가 그 씬을 「일부 건너뜀」으로 센다) — 각 파일 주석과 notes 에만.

module.exports = { PLATE, BUYER, CONSIGNEE, SALESMAN_LABEL, RECEIPT_NOTE, vehicle, buyerRow, ymdOf, typeDate, typeMoney, saveEdit, clickSafe, pdf };
