// 씬 점검 — bank-balance (resources/guides/bank-balance). 촬영 원본 = cap/bk_scene.js
// 계정 = finance@ (재무). 예시 계좌 = 「예시」 000-000-000001(업무) · 000-000-000002(외화) — 시더가 매번 지운다.
const L = require('./lib');

const ACC1 = '000-000-000001';
const ACC2 = '000-000-000002';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('bank-balance');
  const { iso } = L.today();
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const card = () => p.locator('[data-cash-card]').first();
  const accRow = (no) => L.dbOne("select id, is_foreign, is_active from cash_accounts where bank = ? and account_no = ?", ['예시', no]);
  const bal = (accId) => L.dbQuery("select currency, balance from cash_account_balances where cash_account_id = ? and snapshot_date = ?", [accId, iso]);

  try {
    await r.step('사전: 예시 계좌가 비어 있다(시더)', async () => {
      if (accRow(ACC1) || accRow(ACC2)) throw new Error('예시 계좌가 이미 있다 — GuideSceneFixtureSeeder 가 안 돌았다');
    });

    await r.step('대시보드 「통장 마감잔액 입력」 카드', async () => {
      await L.login(p, 'finance@car-erp.test');
      await p.goto(`${L.BASE}/erp/dashboard`, { waitUntil: 'networkidle', timeout: 120000 });
      await card().waitFor({ state: 'visible', timeout: 30000 });
      await L.expectText(p, '통장 마감잔액 입력', '카드 제목', { scope: '[data-cash-card]' });
      await L.expectText(p, '기준일', '기준일 칸', { scope: '[data-cash-card]' });
    });

    const addAcc = async (no, label, foreign, stepName) => {
      await card().locator('button', { hasText: '+ 계좌 추가' }).first().click();
      await L.expectText(p, '계좌번호', `${stepName} 등록 칸`, { scope: '[data-cash-card]' });
      await L.expectText(p, '구분(선택)', `${stepName} 등록 칸`, { scope: '[data-cash-card]' });
      await L.expectText(p, '외화 계좌', `${stepName} 「외화 계좌」 체크`, { scope: '[data-cash-card]' });
      await card().locator('input[wire\\:model="newBank"]').first().fill('예시');
      await card().locator('input[wire\\:model="newAccountNo"]').first().fill(no);
      await card().locator('input[wire\\:model="newLabel"]').first().fill(label);
      if (foreign) await card().locator('input[wire\\:model="newForeign"]').first().check();
      await L.expectAfter(p, () => card().locator('button[wire\\:click="addCashAccount"]').first().click(), '계좌를 등록했습니다.', `${stepName} [등록]`);
      await L.assertNoViolations(p);
      const row = accRow(no);
      if (!row) throw new Error(`${stepName}: DB 에 계좌 없음`);
      L.assertEq(Number(row.is_foreign), foreign ? 1 : 0, `${stepName}: is_foreign`);
    };

    await r.step('1단계 [+ 계좌 추가] → [등록] (원화 계좌)', () => addAcc(ACC1, '업무', false, '원화 계좌'));
    await r.step('1단계 [+ 계좌 추가] → 「외화 계좌」 체크 → [등록]', () => addAcc(ACC2, '외화', true, '외화 계좌'));

    await r.step('1단계 한 번도 안 넣은 계좌 노란 안내', async () => {
      await L.expectText(p, '아직 한 번도 안 넣은 계좌', '노란 안내', { scope: '[data-cash-card]' });
    });

    const pick = async (no) => {
      const sel = card().locator('select[wire\\:model\\.live="cashAccountId"]').first();
      const val = await sel.locator('option', { hasText: no }).first().getAttribute('value');
      await sel.selectOption(val);
      await p.waitForTimeout(1500);
    };

    await r.step('2단계 계좌 고르기 → 원화 칸 → [저장]', async () => {
      await pick(ACC1);
      await card().locator('input[wire\\:model="cashAmounts.KRW"]').first().fill('52,340,000');
      await L.expectAfter(p, () => card().locator('button[wire\\:click="saveAccountBalance"]').first().click(),
        `${iso} 예시(업무) ${ACC1} 잔액을 저장했습니다.`, '원화 저장 토스트');
      await L.assertNoViolations(p);
      const rows = bal(accRow(ACC1).id);
      const krw = rows.find((x) => x.currency === 'KRW');
      if (!krw) throw new Error('DB: 원화 잔액 행 없음');
      L.assertEq(Number(krw.balance), 52340000, 'DB 원화 잔액');
    });

    await r.step('2단계 외화 계좌 → 달러·유로 칸 → [저장] (원화가 따라오지 않는다)', async () => {
      await pick(ACC2);
      // §8 #123 — 앞 계좌에 쓰던 원화가 다음 계좌로 넘어가면 안 된다. 칸이 비어 있어야 한다.
      const leftover = await card().locator('input[wire\\:model="cashAmounts.KRW"]').first().inputValue();
      if (leftover.trim() !== '') throw new Error(`계좌를 바꿨는데 원화 칸에 앞 값이 남아 있음(${leftover})`);
      await card().locator('input[wire\\:model="cashAmounts.USD"]').first().fill('12500');
      await card().locator('input[wire\\:model="cashAmounts.EUR"]').first().fill('3000');
      await L.expectAfter(p, () => card().locator('button[wire\\:click="saveAccountBalance"]').first().click(),
        `${iso} 예시(외화) ${ACC2} 잔액을 저장했습니다.`, '외화 저장 토스트');
      await L.assertNoViolations(p);
      const rows = bal(accRow(ACC2).id);
      const get = (c) => rows.find((x) => x.currency === c);
      L.assertEq(Number(get('USD') && get('USD').balance), 12500, 'DB 달러 잔액');
      L.assertEq(Number(get('EUR') && get('EUR').balance), 3000, 'DB 유로 잔액');
      if (get('KRW')) throw new Error(`DB: 외화 계좌에 원화 행이 생겼다(${get('KRW').balance}) — §8 #123 재발`);
    });

    await r.step('3단계 표 — 합계 · 원화 환산 · [수정]', async () => {
      await L.expectText(p, '합계', '합계 줄', { scope: '[data-cash-card]' });
      await L.expectText(p, '수정', '[수정]', { scope: '[data-cash-card]' });
      // 원화 환산은 실시간 환율 조회 결과에 따라 둘 중 하나가 뜬다(외부 조회 실패는 화면 회귀가 아니다).
      const t = await card().innerText();
      if (!t.includes('원화 환산') && !t.includes('실시간 환율을 불러오지 못해')) throw new Error('원화 환산 줄도, 환율 실패 안내도 없음');
      // 두 계좌 다 넣었으니 노란 안내는 사라져야 한다.
      await L.expectNoText(p, '아직 한 번도 안 넣은 계좌', '노란 안내 사라짐', { scope: '[data-cash-card]' });
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
