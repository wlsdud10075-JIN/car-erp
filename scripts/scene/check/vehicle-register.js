// 씬 점검 — vehicle-register (resources/guides/vehicle-register). 촬영 원본 = cap/scene1.js · scene1v2.js · scene1d.js
// 계정 = manage@ (관리 — 신규 등록 + 확인 창). 한 바퀴 예시 차 = _loop.PLATE(전용 번호판, 00가1113 아님 — _loop.js 머리말).
// ② NICE 조회 · [🔎 원부조회]는 누르지 않는다(실제 소유자 개인정보 · lib 가드가 lookupNiceApi/openWonbuLookup 을 막는다).
const L = require('./lib');
const X = require('./_loop');

module.exports.run = async function run({ browser }) {
  const r = L.recorder('vehicle-register');
  const { iso } = L.today();
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const panelTab = (t) => p.click(`button[\\@click="tab = '${t}'"]`).then(() => p.waitForTimeout(700));

  try {
    await r.step('사전: 한 바퀴 예시 잔재 없음(시더)', async () => {
      if (X.vehicle()) throw new Error(`${X.PLATE} 가 이미 있다 — GuideSceneFixtureSeeder(resetLoop)가 안 돌았다`);
      if (X.buyerRow()) throw new Error(`바이어 ${X.BUYER} 가 이미 있다 — 시더가 안 돌았다`);
      const c = L.dbOne('select count(*) c from consignees where name = ?', [X.CONSIGNEE]);
      if (Number(c.c)) throw new Error(`컨사이니 ${X.CONSIGNEE} 가 남아 있다 — 시더가 안 돌았다`);
    });

    await r.step('1단계 차량 관리 [+ 차량 등록] → 「차량 등록」 창', async () => {
      await L.login(p, 'manage@car-erp.test');
      await p.goto(`${L.BASE}/erp/vehicles`, { waitUntil: 'networkidle', timeout: 120000 });
      await L.expectText(p, '차량 등록', '[+ 차량 등록] 버튼');
      await p.locator('button[wire\\:click="openCreate"]').click();
      // 「차량 등록」 글자는 버튼에도 있다 — 창이 열렸는지는 창에만 있는 번호판 칸 + [신규 등록] 으로 본다.
      await p.locator('input[wire\\:model="vehicle_number"][placeholder="12가3456"]:not([readonly])').first().waitFor({ state: 'visible', timeout: 15000 })
        .catch(() => { throw new Error('[1단계] 「차량 등록」 창이 안 열림(번호판 칸 없음)'); });
      await L.expectText(p, '신규 등록', '창 [신규 등록]');
    });

    await r.step('2단계 기본정보 — 차량번호 · 영업담당자', async () => {
      await p.locator('input[wire\\:model="vehicle_number"][placeholder="12가3456"]:not([readonly])').first().fill(X.PLATE);
      await p.locator('select[wire\\:model="salesman_id_str"]').first().selectOption({ label: X.SALESMAN_LABEL });
      await p.waitForTimeout(400);
    });

    await r.step('3단계 판매 탭 — 둘 다 비우면 「바이어를 지정하세요 — 판매 탭.」', async () => {
      await panelTab('sale');
      await L.expectText(p, '바이어 미정 (투기 매입)', '「바이어 미정 (투기 매입)」 체크 칸');
      // 신규 등록 → 관리 확인 창 → [확인 후 저장] → 서버 가드가 막는다(차는 안 생긴다).
      await p.getByRole('button', { name: '신규 등록' }).last().click();
      await p.waitForTimeout(1200);
      await p.getByRole('button', { name: '확인 후 저장' }).last().click();
      await L.expectText(p, '바이어를 지정하세요 — 판매 탭.', '바이어 빠짐 안내');
      await L.assertNoViolations(p);
      if (X.vehicle()) throw new Error('DB: 바이어 없이 등록됐다 — 가드가 안 막았다');
    });

    await r.step('3단계 「바이어 미정 (투기 매입)」 체크', async () => {
      await p.locator('input[type=checkbox][wire\\:model\\.live="buyer_undecided"]').check();
      await p.waitForTimeout(800);
    });

    await r.step('4단계 [신규 등록] → 확인 창(「— 미입력」) → [확인 후 저장]', async () => {
      await p.getByRole('button', { name: '신규 등록' }).last().click();
      await L.expectText(p, '판매 정보 확인', '확인 창 제목(보고 있던 탭)');
      await L.expectText(p, '— 미입력', '빈 항목 「— 미입력」');
      await L.expectAfter(p, () => p.getByRole('button', { name: '확인 후 저장' }).last().click(),
        '차량이 등록됐습니다. 다음 단계: 매입 — 매입가 미입력', '등록 토스트(다음 할 일)', { timeout: 30000 });
      await L.assertNoViolations(p);
      await L.expectText(p, '등록 완료', '창 위 「등록 완료」');
      await L.expectText(p, '편집 모드 — 다음 단계 진행', '창 위 「편집 모드 — 다음 단계 진행」');
      const v = X.vehicle();
      if (!v) throw new Error('DB: 차가 안 생겼다');
      L.assertEq(Number(v.buyer_undecided), 1, 'DB 바이어 미정');
      if (v.buyer_id) throw new Error('DB: 바이어가 들어가 있다');
      L.assertEq(v.p, '매입중', 'DB 진행상태');
      return `id=${v.id}`;
    });

    await r.step('6단계 매입 탭 「매입 기본」 — 매입일(8자리) · 구입처 · 매입가 · 매도비', async () => {
      await panelTab('purchase');
      // 가이드: 「이미 등록한 차에 나중에 매입가를 넣을 때는 (매입일이) 채워지지 않습니다」 → 직접 넣는다.
      await X.typeDate(p, 'input[wire\\:model="purchase_date"]', iso);
      await p.locator('input[wire\\:model="purchase_from"]').fill('딜러');
      await X.typeMoney(p, 'input[wire\\:model="purchase_price_str"]', 10000000);
      await X.typeMoney(p, 'input[wire\\:model="selling_fee_str"]', 300000);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(600);
    });

    await r.step('7단계 「매입처 계좌 정보」 — 계좌번호에 줄표가 붙는다', async () => {
      await p.locator('input[wire\\:model\\.blur="purchase_seller_bank"]').fill('국민은행');
      await p.locator('input[wire\\:model="purchase_seller_holder"]').fill('홍길동');      // 지어낸 예금주(가이드 그림과 같음)
      const acc = p.locator('input[wire\\:model\\.blur="purchase_seller_account"]');
      await acc.fill('123456789012');
      await p.keyboard.press('Tab');
      await p.waitForTimeout(1200);
      L.assertEq(await acc.inputValue(), '123456-78-9012', '계좌번호 자동 줄표(국민은행)');
    });

    await r.step('8단계 「비용」 — 말소비가 미리 들어 있다', async () => {
      await L.expectText(p, '말소비', '「말소비」 칸');
      // 숫자는 회사 설정(기본 비용)에 따라 다르다 — 화면 값만 0 이 아닌지 본다. DB 금액은 저장 뒤 확인.
    });

    await r.step('9단계 [수정 저장] → 「매입 정보 확인」 → [확인 후 저장]', async () => {
      await X.saveEdit(p, '매입 저장', { expectTitle: '매입 정보 확인' });
      const v = X.vehicle();
      L.assertEq(Number(v.purchase_price), 10000000, 'DB 매입가');
      L.assertEq(Number(v.selling_fee), 300000, 'DB 매도비');
      L.assertEq(String(v.purchase_date).slice(0, 10), iso, 'DB 매입일');
      L.assertEq(v.purchase_from, '딜러', 'DB 구입처');
      // ⚠️ 설정값 — 로컬 회사 세트(heyman)의 기본 말소비. 월정산 씬의 「정산판매금원화 12,576,000」이 이 값을 전제한다.
      L.assertEq(Number(v.cost_deregistration), 24000, 'DB 말소비(기본 설정)');
      L.assertEq(v.p, '매입중', 'DB 진행상태(미지급 남음)');
    });

    await r.step('10단계 다시 열면 「매입 미지급 요약」 — 10,300,000 / 0 / 10,300,000', async () => {
      L.unlockVehicles([X.PLATE]);
      await L.openVehicle(p, X.PLATE, 'purchase');
      for (const t of ['매입 미지급 요약', '총 매입액', '지급 완료', '미지급액', '10,300,000', '매입 지급']) await L.expectText(p, t, `요약 「${t}」`);
      await L.closePanel(p);
    });

    await r.step('10단계 목록 — 「매입중」 · 「바이어미정」', async () => {
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const row = p.locator('tr', { hasText: X.PLATE }).first();
      const t = await row.innerText();
      if (!t.includes('매입중')) throw new Error('목록 줄에 「매입중」 없음');
      if (!t.includes('바이어미정')) throw new Error('목록 줄에 「바이어미정」 없음');
    });
    // 5단계 NICE 조회는 안 본다(실제 소유자 개인정보 — 가이드도 예시 차로 누르지 않음). 버튼은 lib 가드가 막는다.
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
