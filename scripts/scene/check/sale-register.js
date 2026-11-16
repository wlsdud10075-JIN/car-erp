// 씬 점검 — sale-register (resources/guides/sale-register). 촬영 원본 = cap/sr_b.js (앞부분은 scene1 계열에서 찍음)
// 계정 = manage@ (관리 — 확인 창). 차 = _loop.PLATE. 바이어 = [+ 신규] 로 만드는 전용 바이어 _loop.BUYER(시더가 매번 지운다).
//   🚫 GUIDE SAMPLE TRADING 을 다시 만들지 않는다(1단계 buyer-account 가 이름으로 찾는다 — 둘이 되면 깨진다).
//   ⚠️ 바이어를 만들면 Buyer::created 가 같은 이름의 컨사이니를 자동으로 하나 만든다(시더가 buyer_id 로 같이 지운다).
const L = require('./lib');
const X = require('./_loop');

module.exports.run = async function run({ browser }) {
  const r = L.recorder('sale-register');
  const { iso, ymd } = L.today();
  let v;

  await r.step('사전: 앞 씬(purchase-payment) 결과 — 매입완료 · 바이어 미정', async () => {
    v = X.vehicle();
    if (!v || v.p !== '매입완료') { r.skip(`앞 씬 purchase-payment 가 끝나지 않았다(진행상태 ${v ? v.p : '차 없음'})`); return '건너뜀'; }
    if (Number(v.buyer_undecided) !== 1 || v.buyer_id) throw new Error('바이어 미정이 아니다 — 예시 상태가 다르다');
    if (X.buyerRow()) throw new Error(`바이어 ${X.BUYER} 가 이미 있다 — 시더가 안 돌았다`);
    L.unlockVehicles([X.PLATE]);
  });

  const ctx = await L.newContext(browser, { acceptDownloads: true });
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 차를 열고 「판매」 탭 — 「바이어 미정 (투기 매입)」에 체크돼 있다', async () => {
      await L.login(p, 'manage@car-erp.test');
      await L.openVehicle(p, X.PLATE, 'sale');
      const cb = p.locator('input[type=checkbox][wire\\:model\\.live="buyer_undecided"]');
      if (!(await cb.isChecked())) throw new Error('「바이어 미정」 체크가 풀려 있다');
    });

    await r.step('2단계 [+ 신규] → 바이어명 · 영업담당자 → [등록 + 선택]', async () => {
      await p.locator(`button[wire\\:click="openQuickAdd('buyer','sale')"]`).click();
      await L.expectText(p, '신규 바이어 등록', '창 제목');
      await p.locator('input[wire\\:model="qaName"]').fill(X.BUYER);
      await p.locator('select[wire\\:model="qaSalesmanId"]').selectOption({ label: X.SALESMAN_LABEL });
      // 국가(x-country-picker)는 고르지 않는다 — 자동완성 컴포넌트라 깨지기 쉽고, 가이드에서도 필수가 아니다.
      await L.expectAfter(p, () => p.locator('button[wire\\:click="saveQuickAdd"]').click(), '신규 바이어 등록 완료', '[등록 + 선택] 토스트');
      await L.assertNoViolations(p);
      if (!X.buyerRow()) throw new Error('DB: 바이어가 안 생겼다');
      // 가이드: 「바이어가 들어가면 「바이어 미정」 체크 칸은 사라지고」
      await L.expectNoText(p, '바이어 미정 (투기 매입)', '체크 칸 사라짐', { settle: 1200 });
    });

    await r.step('3단계 판매일(8자리) · 통화 · 환율 · 판매가 · 운임비', async () => {
      await X.typeDate(p, 'input[wire\\:model="sale_date"]', iso);
      await p.locator('select[wire\\:model\\.live="currency"]').selectOption('USD');
      await p.waitForTimeout(800);
      await p.locator('input[wire\\:model="exchange_rate_str"]').fill('1400');
      await X.typeMoney(p, 'input[wire\\:model="sale_price_str"]', 9000);
      await X.typeMoney(p, 'input[wire\\:model="transport_fee_str"]', 1000);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(600);
    });

    await r.step('4단계 [수정 저장] → 「판매 정보 확인」 → [확인 후 저장]', async () => {
      await X.saveEdit(p, '판매 저장', { expectTitle: '판매 정보 확인' });
      v = X.vehicle();
      const b = X.buyerRow();
      L.assertEq(v.buyer_id, b.id, 'DB 바이어');
      L.assertEq(Number(v.buyer_undecided), 0, 'DB 바이어 미정 풀림(저장할 때 자동)');
      L.assertEq(v.currency, 'USD', 'DB 통화');
      L.assertEq(Number(v.exchange_rate), 1400, 'DB 환율');
      L.assertEq(Number(v.sale_price), 9000, 'DB 판매가');
      L.assertEq(Number(v.transport_fee), 1000, 'DB 운임비');
      L.assertEq(String(v.sale_date).slice(0, 10), iso, 'DB 판매일');
      L.assertEq(v.p, '판매중', 'DB 진행상태');
    });

    await r.step('5단계 다시 열면 「판매 미입금 요약」 USD 10,000 (100.0%)', async () => {
      L.unlockVehicles([X.PLATE]);
      await L.openVehicle(p, X.PLATE, 'sale');
      for (const t of ['판매 미입금 요약', '총 판매액', '입금 완료', '미입금액', 'USD 10,000', '100.0%']) await L.expectText(p, t, `요약 「${t}」`);
    });

    await r.step('6단계 「서류」 탭 → Proforma Invoice 엑셀', async () => {
      await p.click(`button[\\@click="tab = 'docs'"]`);
      await p.waitForTimeout(800);
      await L.expectText(p, '판매 서류 (수출)', '「판매 서류 (수출)」');
      await L.expectText(p, 'Sales Contract', '옆의 Sales Contract');
      const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), p.locator('a[href*="/documents/invoice"]').first().click()]);
      L.assertEq(dl.suggestedFilename(), `Invoice_${X.PLATE}_${ymd}.xlsx`, '인보이스 파일명');
      await dl.cancel().catch(() => {});
      await L.assertNoViolations(p);
      await L.closePanel(p);
    });

    await r.step('5단계 목록 — 「판매중」 · 「바이어미정」 사라짐', async () => {
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      if (!t.includes('판매중')) throw new Error('목록 줄에 「판매중」 없음');
      if (t.includes('바이어미정')) throw new Error('목록 줄에 「바이어미정」이 남아 있다');
    });
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
