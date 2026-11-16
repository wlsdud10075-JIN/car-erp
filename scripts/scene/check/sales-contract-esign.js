// 씬 점검 — sales-contract-esign (resources/guides/sales-contract-esign). 촬영 원본 = cap/es_scene.js · es_scene2.js
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = guide.manager@. 바이어 GUIDE SAMPLE TRADING.
// 🚫 서명 제출까지 가지 않는다 — 서명 완료 행은 지울 수 없고(법적 증거물, SignedContract::deleting) 제출하면 서명본 메일이 나간다.
//    그래서: [전자서명 요청] → 발급 토스트 · 링크 창 → (링크가 L.BASE 일 때만) 바이어 화면 열기 → ERP 「열람됨」 → [무르기].
//    「서명완료 · 서명본 보기」 는 00가1131 의 기존 서명 행(촬영 때 만든 것)으로 **읽기만** 확인한다.
// 차 고르기: 가이드 예시 00가1131 은 이미 서명 완료라 [전자서명 요청] 이 안 뜬다(pickForSet 이 signed 를 고른다).
//    → 아래 후보 중 「그 차 한 대짜리 세션(대기·열람·서명)이 없는」 첫 차를 쓴다. 남는 무른 행은 시더가 지운다.
const L = require('./lib');

const BUYER = 'GUIDE SAMPLE TRADING';
const SIGNED_PLATE = '00가1131';
const CANDIDATES = ['00가1151', '00가1152', '00가1122', '00가1121'];

module.exports.run = async function run({ browser }) {
  const r = L.recorder('sales-contract-esign');
  let plate;
  let vid;
  const sessions = (id) => L.dbQuery("select id, status from signed_contracts where vehicle_ids = ? order by id desc", [JSON.stringify([id])]);
  const latest = () => sessions(vid)[0] || null;

  await r.step('사전: 서명 세션이 없는 예시 차 고르기 · 1131 서명 완료 행', async () => {
    for (const pl of CANDIDATES) {
      const v = L.dbOne("select v.id, v.sale_price, v.sales_channel, b.name bname from vehicles v left join buyers b on b.id = v.buyer_id where v.vehicle_number = ? and v.deleted_at is null", [pl]);
      if (!v || v.bname !== BUYER || !(Number(v.sale_price) > 0) || v.sales_channel !== 'export') continue;
      if (sessions(v.id).some((s) => ['pending', 'viewed', 'signed'].includes(s.status))) continue;
      plate = pl; vid = v.id; break;
    }
    if (!plate) throw new Error(`후보(${CANDIDATES.join('·')}) 전부 서명 세션이 있다 — 시더가 무른 행을 안 지웠거나 서명 완료됐다`);
    L.unlockVehicles([plate, SIGNED_PLATE]);
    return `차 = ${plate}`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const openDocs = async (pl) => {
    await L.openVehicle(p, pl, 'docs');
    await p.locator('text=전자서명').first().evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await p.waitForTimeout(400);
  };
  let url = null;
  try {
    await r.step('1단계 「서류」 탭 → [전자서명 요청] → 발급 토스트 · 링크 창', async () => {
      await L.login(p, 'guide.manager@car-erp.test');
      await openDocs(plate);
      await L.expectAfter(p, () => p.locator('button', { hasText: '전자서명 요청' }).first().click(), '전자서명 링크를 발급했습니다.', '발급 토스트');
      await L.assertNoViolations(p);
      for (const t of ['복사', '링크는 7일 후 만료됩니다.']) await L.expectText(p, t, `링크 창 「${t}」`);
      url = await p.evaluate(() => {
        const el = [...document.querySelectorAll('input,textarea,code,a,span,div')].find((e) => /\/sign\/[^\s"]+signature=/.test(e.value || e.textContent || ''));
        if (!el) return null;
        const m = (el.value || el.textContent).match(/https?:\/\/[^\s"]+\/sign\/[^\s"]+/);
        return m ? m[0] : null;
      });
      if (!url) throw new Error('링크 창에서 서명 링크를 못 찾았다');
      const s = latest();
      if (!s || s.status !== 'pending') throw new Error(`DB: 새 세션이 pending 이 아니다(${s && s.status})`);
    });

    await r.step('2단계 바이어 화면 — 로그인 없이 · 이름 · 이메일 · 서명 칸(제출 안 함)', async () => {
      // 🚫 링크는 로컬에서만 연다 — APP_URL 이 운영 도메인이면 그 링크는 운영 서버로 간다(§8 #76).
      if (!url.startsWith(L.BASE)) throw new Error(`링크가 로컬(${L.BASE})이 아니다 — 열지 않는다`);
      const bctx = await L.newContext(browser, { viewport: { width: 420, height: 900 }, locale: 'en-US' });
      try {
        const bp = await L.newPage(bctx);
        await bp.goto(url, { waitUntil: 'networkidle', timeout: 120000 });
        for (const t of ['Clear · 지우기', 'Sign & submit · 서명 제출']) await L.expectText(bp, t, `바이어 화면 「${t}」`);
        for (const sel of ['#signer_name', '#recipient_email', '#pad']) if (!(await bp.locator(sel).count())) throw new Error(`바이어 화면에 ${sel} 없음`);
      } finally {
        await bctx.close();
      }
      L.assertEq(latest().status, 'viewed', 'DB 링크를 열면 「열람됨」');
    });

    await r.step('3단계 ERP 「열람됨 · 링크복사」 → [무르기] → 다시 [전자서명 요청]', async () => {
      await L.closePanel(p);
      L.unlockVehicles([plate]);
      await openDocs(plate);
      await L.expectText(p, '열람됨 · 링크복사', '「열람됨 · 링크복사」');
      await L.expectAfter(p, () => p.locator('button', { hasText: '무르기' }).first().click(), '전자서명 요청을 무르고 링크를 무효화했습니다.', '[무르기] 토스트');
      await L.assertNoViolations(p);
      L.assertEq(latest().status, 'revoked', 'DB 무른 세션');
      await L.expectText(p, '전자서명 요청', '무른 뒤 [전자서명 요청] 다시 보임');
      await L.closePanel(p);
      L.unlockVehicles([plate]);
    });

    await r.step('3단계(읽기) 서명 완료 차 — 「서명완료 · 서명본 보기」', async () => {
      const sid = L.dbOne('select id from vehicles where vehicle_number = ? and deleted_at is null', [SIGNED_PLATE]);
      const signed = sid && sessions(sid.id).find((s) => s.status === 'signed');
      if (!signed) { return '건너뜀: 1131 서명 완료 행 없음(이 PC 에서는 확인 불가)'; }
      await openDocs(SIGNED_PLATE);
      await L.expectText(p, '서명완료 · 서명본 보기', '「서명완료 · 서명본 보기」');
      await L.closePanel(p);
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles([plate, SIGNED_PLATE].filter(Boolean));
  return r.result();
};
