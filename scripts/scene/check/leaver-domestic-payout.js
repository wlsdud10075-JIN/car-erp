// 씬 점검 — leaver-domestic-payout (resources/guides/leaver-domestic-payout). 촬영 원본 = cap/lv_scene.js · lv_explore.js
// ⚠️ 초안 — 한 번도 실행하지 않았다. **읽기 전용 씬**(촬영도 모달까지만 열었다) — 되돌리기 불필요.
// 계정 = guide.manager@. 데모 퇴사자 = 「[LEAVERDEMO] 이승훈」(LeaverDemoSeeder · 로컬 전용·미커밋 — 없으면 건너뜀).
// 🚫 [퇴사 정산 제출](submitLeaverBatch) · [결재 올리기](submitDomestic) 는 누르지 않는다 — 배치가 생기고 결재 알림톡이 나간다.
//    이 파일이 그 두 메서드를 이 씬 동안 네트워크에서 막는다(lib 전역 목록 추가 제안 = touches.md).
const L = require('./lib');
const H = require('./p3-helpers');

const DEMO = '[LEAVERDEMO]';
const LOCAL_FORBIDDEN = ['submitLeaverBatch', 'submitDomestic'];

module.exports.run = async function run({ browser }) {
  const r = L.recorder('leaver-domestic-payout');

  await r.step('사전: 데모 퇴사자 존재', async () => {
    const n = Number(L.dbOne("select count(*) c from salesmen where name like ? and deleted_at is null", [`${DEMO}%`]).c);
    if (!n) { r.skip('데모 퇴사자([LEAVERDEMO]) 없음 — LeaverDemoSeeder 를 먼저(로컬 전용)'); return ''; }
    return `${n}명`;
  });

  const ctx = await L.newContext(browser);
  // 씬 한정 가드 — 나중 등록한 route 가 먼저 돈다. 걸리면 abort + 위반 기록, 아니면 lib 의 route 로 넘긴다.
  await ctx.route(/\/livewire[^/]*\/update/, (rt) => {
    const body = rt.request().postData() || '';
    const hit = LOCAL_FORBIDDEN.find((m) => new RegExp(`"method"\\s*:\\s*"${m}"`).test(body));
    if (hit) { ctx.violations.push(`network:${hit}`); return rt.abort(); }
    return rt.fallback();
  });
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 정산 관리 「퇴사자 정산」 펼침 — 줄 · 칸 이름', async () => {
      await L.login(p, 'guide.manager@car-erp.test');
      await p.goto(`${L.BASE}/erp/settlements`, { waitUntil: 'networkidle', timeout: 120000 });
      await H.hideAlarm(p);
      await p.locator('button[wire\\:click="toggleLeavers"]').first().evaluate((e) => e.click());
      await p.waitForTimeout(1500);
      await H.hideAlarm(p);
      for (const t of ['지급 가능(확정)', '먼저 처리할 것', '미청산 이월', '예치금', DEMO]) await L.expectText(p, t, `「${t}」`);
      await L.assertNoViolations(p);
    });

    await r.step('2단계 [퇴사 정산 제출] 창 — 예치금 반환 줄 · 수기 조정 · 최종 지급총액(제출 안 함)', async () => {
      const btn = p.locator('button[wire\\:click^="openLeaverModal"]');
      if (!(await btn.count())) return '건너뜀: 제출 가능한 퇴사자 줄 없음(「정리 후 제출」만 있음)';
      await btn.first().evaluate((e) => e.click());   // 촬영(lv_scene.js)과 같이 evaluate
      await p.waitForTimeout(2000);
      await H.hideAlarm(p);
      await L.expectText(p, '퇴사 정산 제출', '창 제목');
      await L.expectText(p, '이 금액으로 승인 요청 알림톡이 나갑니다', '창 안내');
      await p.locator('button[wire\\:click="closeLeaverModal"]').first().evaluate((e) => e.click());
      await p.waitForTimeout(800);
      await L.assertNoViolations(p);
    });

    await r.step('3단계 「내수직원 월정산」 — 올릴 달 · [결재 올리기] 보임(안 누름)', async () => {
      const resp = await p.goto(`${L.BASE}/erp/domestic-payouts`, { waitUntil: 'networkidle', timeout: 120000 });
      if (!resp || resp.status() === 403) return '건너뜀: 이 계정은 볼 사람으로 지정되지 않았다(403)';
      await L.expectText(p, '내수직원 월정산', '화면 제목');
      const body = await p.locator('body').innerText();
      if (!body.includes('올릴 달')) return '건너뜀: 「올릴 달」 칸 없음(내수 월정산 미사용 회사이거나 표 미업로드)';
      if (!body.includes('이 달은 이미 올림') && !(await p.locator('button[wire\\:click="submitDomestic"]').count())) {
        if (!body.includes('평가수수료 표가 없습니다')) throw new Error('[결재 올리기] 도 「표가 없습니다」 안내도 없다');
      }
      await L.assertNoViolations(p);
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
