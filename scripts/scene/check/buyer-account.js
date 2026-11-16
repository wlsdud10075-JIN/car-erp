// 씬 점검 — buyer-account (resources/guides/buyer-account). 촬영 원본 = cap/ba_scene.js · ba_fee.js
// 계정 = finance@. 바이어 = GUIDE SAMPLE TRADING · 차 = 00가1131(USD).
// 흐름: 현금 입금 기재 USD 3,000 → 채권관리 「입금」 2,000 → 재무 처리 확정(현금에서 2,000) → 바이어 정산현황 → 수수료 25 털기
//   ⇒ 그 입금의 남은 금액 3,000 → 1,000 → 975 (가이드 숫자). 이 숫자는 그 바이어에게 「남은 현금이 있는 다른 입금」이 없을 때만 맞다.
// 🚨 확정으로 차가 완납되면 정산이 자동 생성되고 erp_settle_pending 알림톡이 서버에서 나간다(Settlement::created —
//    로컬 BizM 은 실발송). 클릭 가드로는 못 막으니 사전 조건으로 「2,000 을 넣어도 미수가 남는다」를 DB 로 본다.
const L = require('./lib');

const BUYER = 'GUIDE SAMPLE TRADING';
const PLATE = '00가1131';
const RCPT_NOTE = '11/14 송금 (가이드 예시)';
const HIST_NOTE = '11/14 송금분 일부';
const FEE_NOTE = '중계은행 수수료';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('buyer-account');
  const { iso } = L.today();
  let buyer;
  let veh;
  const receipt = () => L.dbOne('select id, currency, amount from buyer_cash_receipts where buyer_id = ? and note = ?', [buyer.id, RCPT_NOTE]);
  const allocated = (rid) => Number(L.dbOne('select coalesce(sum(amount),0) s from buyer_cash_allocations where receipt_id = ?', [rid]).s);

  // ── 사전 조건 (화면 전) ──
  await r.step('사전: 현금 원장 사용 회사(토글 ON)', async () => {
    const set = (L.dbOne("select value from settings where `key` = 'company_template_set'") || {}).value || 'system';
    const on = L.dbOne('select value from settings where `key` = ?', [`buyer_cash_enabled_${set}`]);
    if (!on || !['1', 'true'].includes(String(on.value))) {
      r.skip(`이 로컬 회사(${set})는 바이어 현금 원장 미사용 — 가이드상 「현금」 탭·「바이어 정산현황」이 없다`);
      return '토글 OFF';
    }
    return `회사 세트 ${set}`;
  });
  await r.step('사전: 예시 바이어·차 존재 · 씬 잔재 없음(시더)', async () => {
    buyer = L.dbOne('select id from buyers where name = ? and deleted_at is null', [BUYER]);
    veh = L.dbOne('select id, currency, exchange_rate, sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
    if (!buyer || !veh) throw new Error('예시 바이어(GUIDE SAMPLE TRADING) 또는 00가1131 없음');
    if (receipt()) throw new Error('씬 입금 기재가 남아 있다 — GuideSceneFixtureSeeder 가 안 돌았다');
    // 다른 입금에 남은 현금이 있으면 FIFO 로 거기서 먼저 빠져 3,000→1,000 이 성립하지 않는다.
    const other = L.dbQuery("select r.id, r.amount, coalesce((select sum(a.amount) from buyer_cash_allocations a where a.receipt_id = r.id),0) used from buyer_cash_receipts r where r.buyer_id = ? and r.currency = 'USD'", [buyer.id]);
    const left = other.filter((x) => Number(x.amount) - Number(x.used) > 0.001);
    if (left.length) throw new Error(`다른 USD 입금에 남은 현금이 있다(${left.length}건) — 가이드 숫자가 안 맞는다`);
  });
  await r.step('사전: 2,000 확정해도 완납이 아니다(정산 자동 생성 → 알림톡 실발송 방지)', async () => {
    const need = 2000 * Number(veh.exchange_rate) + 1000;   // 여유 1,000원
    if (veh.currency !== 'USD') throw new Error(`00가1131 통화가 USD 가 아님(${veh.currency})`);
    if (!(Number(veh.u) > need)) throw new Error(`중단: 미수(원화 캐시 ${veh.u})가 2,000 USD 이하 — 확정하면 완납 → 정산 생성 → 알림톡이 나갈 수 있다`);
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const openCashTab = async () => {
    await p.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(BUYER)}`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(1200);
    await p.locator('tr', { hasText: BUYER }).first().click();
    await p.waitForTimeout(2000);
    await p.locator('button', { hasText: /^현금$/ }).first().click();
    await p.waitForTimeout(800);
  };
  try {
    await r.step('1단계 바이어를 열고 「현금」 탭 → 「입금 기재」', async () => {
      await L.login(p, 'finance@car-erp.test');
      await openCashTab();
      await L.expectText(p, '통화별 현금', '「통화별 현금」');
      await L.expectText(p, '입금 기재', '「입금 기재」');
      await p.locator('input[wire\\:model="cash_date"]').fill(iso);
      await p.locator('select[wire\\:model="cash_currency"]').selectOption('USD');
      await p.locator('input[wire\\:model="cash_amount"]').fill('3000');
      await p.locator('input[wire\\:model="cash_note"]').fill(RCPT_NOTE);
      await L.expectAfter(p, () => p.getByRole('button', { name: '입금 기재' }).click(), '입금을 기재했습니다.', '[입금 기재]');
      await L.assertNoViolations(p);
      const rc = receipt();
      if (!rc) throw new Error('DB: 입금 기재 행 없음');
      L.assertEq(Number(rc.amount), 3000, 'DB 입금액');
      L.assertEq(rc.currency, 'USD', 'DB 통화');
      await L.expectText(p, '다음에 여기서 나갑니다', '「↓ 다음에 여기서 나갑니다」');
    });

    await r.step('2단계 채권관리 「입금」 2,000 (판매 잔금 생성, 미확정)', async () => {
      await p.goto(`${L.BASE}/erp/receivables`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
      await p.getByRole('button', { name: '조회', exact: true }).click();   // 채권관리 검색 [조회] — NICE 아님(가드는 메서드명으로 막는다)
      await p.waitForTimeout(1500);
      await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2000);
      await p.locator('input[wire\\:model="hAmount"]').fill('2000');
      await p.locator('input[wire\\:model="hExchangeRate"]').fill('1460');
      await p.locator('textarea[wire\\:model="hNote"]').fill(HIST_NOTE);
      await p.getByRole('button', { name: '추가', exact: true }).click();
      await p.waitForTimeout(2500);
      await L.assertNoViolations(p);
      const h = L.dbOne("select h.final_payment_id fp, f.confirmed_at c from receivable_histories h left join final_payments f on f.id = h.final_payment_id where h.vehicle_id = ? and h.method = 'deposit' and h.note = ?", [veh.id, HIST_NOTE]);
      if (!h || !h.fp) throw new Error('DB: 채권 입금 → 판매 잔금 연결 없음');
      if (h.c) throw new Error('DB: 잔금이 벌써 확정돼 있다(재무 처리 전이어야 한다)');
    });

    await r.step('2단계 「재무 처리」 → [재무 처리 완료] → 현금에서 빠짐', async () => {
      await p.goto(`${L.BASE}/erp/transfers?tabType=sale_payment&search=${encodeURIComponent(PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(1200);
      await p.locator('tr', { hasText: PLATE }).first().locator('button', { hasText: '재무 처리 완료' }).first().click();
      await L.expectText(p, '판매 잔금 재무 확정', '확정 창 제목');   // transfer.confirm_modal.title_sale
      await L.expectAfter(p, () => p.locator('button[wire\\:click="confirmPayment"]').first().click(), '판매 잔금 재무 확정 완료', '확정 토스트');
      await L.assertNoViolations(p);
      const rc = receipt();
      L.assertEq(allocated(rc.id), 2000, 'DB 그 입금에서 쓴 금액');
      L.assertEq(Number(rc.amount) - allocated(rc.id), 1000, 'DB 남은 현금 3,000 → 1,000');
      const st = L.dbOne("select count(*) c from settlements where vehicle_id = ? and deleted_at is null and settlement_status = 'pending'", [veh.id]);
      if (Number(st.c) > 0) throw new Error('정산이 생겼다 — 완납 진입(알림톡 발송 가능성). 예시 차 상태를 확인할 것');
    });

    await r.step('3단계 메뉴 「바이어 정산현황」에서 바이어', async () => {
      // 콤보박스 대신 #[Url] buyerId 로 연다(촬영 때 콤보 선택이 불안정했다).
      await p.goto(`${L.BASE}/erp/buyer-account?buyerId=${buyer.id}`, { waitUntil: 'networkidle', timeout: 120000 });
      for (const t of ['바이어 정산현황', '남은 현금', '받을 돈', '현금 사용 내역', '차량별로 보기', '엑셀 내려받기', '묶음별 남은 금액', '미수 차량']) {
        await L.expectText(p, t, `「${t}」`);
      }
      await L.expectText(p, PLATE, '사용 내역에 00가1131');
      // 두 보기 버튼은 늘 함께 있다 — 바뀌는 것은 옆 안내 문구(usage_view_hint_*)다.
      await L.expectText(p, '이 송금이 어디로 갔나', '기본 = 입금별 보기');
      await p.locator(`button[wire\\:click="switchUsageView('vehicle')"]`).click();
      await L.expectText(p, '이 차에 얼마 들어갔나', '[차량별로 보기] → 차량별 안내');
      await L.assertNoViolations(p);
    });

    await r.step('4단계 [＋ 수수료] → [수수료 털기]', async () => {
      await openCashTab();
      await p.getByRole('button', { name: /수수료$/ }).first().click();
      await L.expectText(p, '지금 털 수 있는 최대', '「지금 털 수 있는 최대」');
      await p.locator('input[wire\\:model="fee_amount"]').fill('25');
      await p.locator('input[wire\\:model="fee_note"]').fill(FEE_NOTE);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="addCashFee"]').click(), '수수료를 처리했습니다.', '[수수료 털기]');
      await L.assertNoViolations(p);
      await L.expectText(p, '원장 수수료', '쓴 내역 「원장 수수료」');
      await L.expectText(p, '수수료 내역', '「수수료 내역」');
      const rc = receipt();
      L.assertEq(Number(rc.amount) - allocated(rc.id), 975, 'DB 남은 금액 1,000 → 975');
      const fee = L.dbOne('select amount from buyer_cash_fees where buyer_id = ? and note = ?', [buyer.id, FEE_NOTE]);
      if (!fee) throw new Error('DB: 수수료 행 없음');
      L.assertEq(Number(fee.amount), 25, 'DB 수수료');
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
