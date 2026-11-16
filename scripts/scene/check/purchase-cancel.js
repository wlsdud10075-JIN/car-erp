// 씬 점검 — purchase-cancel (resources/guides/purchase-cancel). 촬영 원본 = cap/cancel_scene.js · cancel_part2.js · mk_cancel.php
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 예시 차 00가1151 · 00가1152 = USD 판매 10,000 · 입금 0 · 담당 프리랜서(이프리).
// ① 업무관리자 — 1151 [매입취소로 전환] → 판매가 500(위약금) 저장(「판매 정보 확인」 → [확인 후 저장])
// ② 재무 — 채권관리 「입금」 500 → 재무 처리 확정 → 1151 「취소완료」 · 목록 「거래완료」
// ③ 업무관리자 — 1152 전환 → 500 저장 → [미수 마감] → 손실 700,000 확정 · 「미수마감」
// 💳 현금 원장이 켜진 회사면 ②의 확정 전에 「현금」 탭에 500 만 기재한다(비고 표식) — 남기면 buyer-account 사전조건이 깨진다.
// ⚠️ 1152 미수마감 손실(프리랜서 절반 350,000)은 월정산 손실요약에 잡힌다 — 2단계 monthly-payout 보다 뒤에 둘 것.
const L = require('./lib');
const H = require('./p3-helpers');

const P1 = '00가1151';
const P2 = '00가1152';
const BUYER = 'GUIDE SAMPLE TRADING';
const PEN_NOTE = '위약금 입금';
const RCPT_NOTE = '위약금 예시 입금 (씬 점검)';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('purchase-cancel');
  const v = (pl) => L.dbOne('select id, currency, sale_price, cancel_status, cancel_shortfall_krw, cancel_loss_settled_at, progress_status_cache from vehicles where vehicle_number = ? and deleted_at is null', [pl]);
  const ledger = H.cashLedgerOn();

  await r.step('사전: 두 차 = 매입취소 아님 · 판매가 USD 10,000 · 입금 0(시더)', async () => {
    for (const pl of [P1, P2]) {
      const x = v(pl);
      if (!x) throw new Error(`예시 차량 ${pl} 없음`);
      L.assertEq(x.cancel_status, 'none', `${pl} 매입취소 상태`);
      L.assertEq(Number(x.sale_price), 10000, `${pl} 판매가`);
      const paid = Number(L.dbOne('select count(*) c from final_payments where vehicle_id = ?', [x.id]).c);
      L.assertEq(paid, 0, `${pl} 판매 잔금 행`);
    }
    L.unlockVehicles([P1, P2]);
    return `현금 원장 ${ledger.on ? '켜짐' : '꺼짐'}`;
  });

  const m = await L.newContext(browser);
  const mp = await L.newPage(m);
  const dialogs = H.watchDialogs(mp);
  const f = await L.newContext(browser);
  const fp = await L.newPage(f);
  const toCancelBox = async () => {
    await mp.locator('text=매입취소').first().evaluate((e) => e.scrollIntoView({ block: 'center' })).catch(() => {});
    await mp.waitForTimeout(400);
  };
  // 안내문에도 「취소완료」·「미수 마감」 글자가 있다 — 매입취소 칸 머리의 뱃지 요소로 본다.
  const badge = async (label, cls, what) => {
    const b = mp.locator(`span.badge.${cls}:visible`, { hasText: new RegExp(`^${label}$`) });
    await b.first().waitFor({ timeout: 15000 }).catch(() => { throw new Error(`[${what}] 뱃지 없음`); });
  };
  const markAndPenalty = async (plate) => {
    await L.openVehicle(mp, plate, 'purchase');
    await toCancelBox();
    await L.expectAfter(mp, () => mp.locator('button[wire\\:click="markPurchaseCancelled"]').click(),
      '매입취소로 전환했습니다. 위약금은 판매 탭에 판매가로 입력하세요.', `${plate} [매입취소로 전환] 토스트`);
    await L.assertNoViolations(mp);
    await L.expectText(mp, '취소 해제', `${plate} [취소 해제] 보임`);
    await badge('매입취소', 'badge-red', `${plate} 빨간 「매입취소」`);
    L.assertEq(v(plate).cancel_status, 'cancelled', `DB ${plate} 매입취소 상태`);
    await mp.click(`button[\\@click="tab = 'sale'"]`);
    await mp.waitForTimeout(800);
    const sp = mp.locator('input[wire\\:model="sale_price_str"]');
    await sp.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await sp.fill('500');
    await mp.keyboard.press('Tab');
    await mp.waitForTimeout(500);
    await H.saveVehicle(mp, `${plate} 위약금 저장`, { modalTitle: '판매 정보 확인' });
    L.assertEq(Number(v(plate).sale_price), 500, `DB ${plate} 판매가(위약금)`);
    await L.closePanel(mp);
    L.unlockVehicles([plate]);
  };

  try {
    await r.step('1·2단계 1151 [매입취소로 전환] → 판매가 500 저장', async () => {
      await L.login(mp, 'guide.manager@car-erp.test');
      await markAndPenalty(P1);
    });

    await r.step('3단계 재무 — 위약금 입금 500 → 확정 → 「취소완료」', async () => {
      await L.login(fp, 'finance@car-erp.test');
      if (ledger.on) await H.addCashReceipt(fp, BUYER, { amount: 500, note: RCPT_NOTE });
      await fp.goto(`${L.BASE}/erp/receivables`, { waitUntil: 'networkidle', timeout: 120000 });
      await fp.locator('input[wire\\:model="search"]').first().fill(P1);
      await fp.getByRole('button', { name: '조회', exact: true }).click();
      await fp.waitForTimeout(1800);
      await fp.locator('tr', { hasText: P1 }).first().locator('td').nth(1).click();
      await fp.waitForTimeout(2200);
      await fp.locator('input[wire\\:model="hAmount"]').fill('500');
      await fp.locator('input[wire\\:model="hExchangeRate"]').fill('1400');
      await fp.locator('textarea[wire\\:model="hNote"]').fill(PEN_NOTE);
      await L.expectAfter(fp, () => fp.getByRole('button', { name: '추가', exact: true }).click(), '회수 이력이 추가됐습니다.', '위약금 입금 [추가]');
      await fp.goto(`${L.BASE}/erp/transfers?tabType=sale_payment&search=${encodeURIComponent(P1)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await fp.waitForTimeout(1200);
      await fp.locator('tr', { hasText: P1 }).first().locator('button', { hasText: '재무 처리 완료' }).first().click();
      await L.expectAfter(fp, () => fp.locator('button[wire\\:click="confirmPayment"]').first().click(), '판매 잔금 재무 확정 완료', '위약금 확정 토스트');
      await L.assertNoViolations(fp);
      const x = v(P1);
      L.assertEq(x.cancel_status, 'cancelled', 'DB 1151 상태(취소완료는 미수 0 인 cancelled)');
      L.assertEq(x.progress_status_cache, '거래완료', 'DB 1151 진행상태');
      L.unlockVehicles([P1]);
      await L.openVehicle(mp, P1, 'purchase');
      await toCancelBox();
      await badge('취소완료', 'badge-gray', '1151 회색 「취소완료」');
      // 안내문(hint)에도 「미수 마감」 글자가 있어 문구로 못 본다 — 버튼 자체를 센다.
      if (await mp.locator('button[wire\\:click="closePurchaseCancelUnpaid"]:visible').count()) throw new Error('취소완료 차에 [미수 마감] 버튼이 보인다');
      await L.closePanel(mp);
      L.unlockVehicles([P1]);
    });

    await r.step('4단계 1152 전환 → 500 → [미수 마감] → 확인 창 → 손실 700,000', async () => {
      await markAndPenalty(P2);
      await L.openVehicle(mp, P2, 'purchase');
      await toCancelBox();
      dialogs.length = 0;
      await L.expectAfter(mp, () => mp.locator('button[wire\\:click="closePurchaseCancelUnpaid"]').click(),
        '미수 마감했습니다. 손실 700,000원 확정(프리랜서 절반 부담).', '[미수 마감] 토스트');
      await L.assertNoViolations(mp);
      if (!dialogs.some((d) => d.includes('위약금 수금을 포기하고 마감합니다'))) throw new Error('확인 창 문구 「위약금 수금을 포기하고 마감합니다…」 없음');
      const x = v(P2);
      L.assertEq(x.cancel_status, 'cancelled_closed', 'DB 1152 상태');
      L.assertEq(Number(x.cancel_shortfall_krw), 700000, 'DB 1152 손실(USD 500 × 1,400)');
      await badge('미수마감', 'badge-gray', '1152 「미수마감」');
      await L.closePanel(mp);
    });

    await r.step('알아 둘 것 — 목록 진행상태 옆 「취소완료」·「미수마감」', async () => {
      await mp.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent('00가115')}`, { waitUntil: 'networkidle', timeout: 120000 });
      await mp.waitForTimeout(1200);
      const row = async (pl) => (await mp.locator('tr', { hasText: pl }).first().innerText());
      if (!(await row(P1)).includes('취소완료')) throw new Error('목록 1151 줄에 「취소완료」 없음');
      if (!(await row(P2)).includes('미수마감')) throw new Error('목록 1152 줄에 「미수마감」 없음');
    });
  } finally {
    await m.close();
    await f.close();
  }
  L.unlockVehicles([P1, P2]);
  return r.result();
};
