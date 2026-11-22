// 씬 점검 — audit-log (resources/guides/audit-log). 촬영 원본 = cap/al_scene.cjs
// 계정 = manage@ (관리). 읽기만 한다 — 저장·되돌리기 없음.
// 🇰🇷 이 씬의 핵심 = **영어로 남은 이름 찾기** (jin 2026-11-22 «개발하고 추가될 때마다 영어 이름 남고 한글화 안 되고 꽤 신경써줘야»).
//   감사 액션·대상·칸을 새로 만들고 사전(config/column_labels.php)·구역표(App\Support\AuditArea)에 안 넣으면 여기서 걸린다.
const L = require('./lib');

const AREAS = ['전체', '기본정보', '매입', '말소', '판매·입금', '수출·선적', '정산', '바이어·컨사이니', '사람·권한', '설정·기타'];
// 한글이 없어도 되는 이름(고유명사·약어) — 늘릴 땐 이유를 적는다.
const ALLOW_NO_KOREAN = [/^B\/L$/, /^-$/, /^✕$/, /^×$/];
const hasKo = (s) => /[가-힣]/.test(s);
const englishOnly = (list) => list.map((s) => s.trim()).filter((s) => s && !hasKo(s) && !ALLOW_NO_KOREAN.some((rx) => rx.test(s)));

module.exports.run = async function run({ browser }) {
  const r = L.recorder('audit-log');
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const chip = (key) => p.locator(`[data-audit-area="${key}"]`).first();
  const combo = (ph) => p.locator(`input[placeholder*="${ph}"]`).first();
  // 열린 콤보의 선택지 글자(펼쳐진 목록 안의 버튼만)
  const openOptions = async (ph) => {
    await combo(ph).click();
    await p.waitForTimeout(600);
    const texts = await p.evaluate((placeholder) => {
      const input = Array.from(document.querySelectorAll('input')).find((i) => (i.getAttribute('placeholder') || '').includes(placeholder));
      const root = input ? input.closest('[x-data]') : null;
      if (!root) return [];
      return Array.from(root.querySelectorAll('button, li')).filter((b) => b.offsetParent !== null).map((b) => (b.innerText || '').trim());
    }, ph);
    await p.keyboard.press('Escape');
    await p.mouse.click(5, 5);
    await p.waitForTimeout(200);
    return texts;
  };

  try {
    await r.step('1단계 구역 칩 10개가 건수와 함께 보인다', async () => {
      await L.login(p, 'manage@car-erp.test');
      await p.goto(`${L.BASE}/admin/audit-logs`, { waitUntil: 'networkidle', timeout: 120000 });
      await L.expectText(p, '감사 로그', '화면 제목');
      const chips = (await p.locator('[data-audit-area]').allInnerTexts()).map((s) => s.replace(/\s+/g, ' ').trim());
      L.assertEq(chips.length, AREAS.length, '칩 개수');
      AREAS.forEach((name, i) => { if (!chips[i].startsWith(name)) throw new Error(`칩 ${i + 1} = 「${chips[i]}」 (기대 「${name}」)`); });
      const nums = chips.map((s) => Number((s.match(/([\d,]+)\s*$/) || [0, '0'])[1].replace(/,/g, '')));
      const sum = nums.slice(1).reduce((a, b) => a + b, 0);
      L.assertEq(sum, nums[0], '구역 건수의 합 = 전체 건수(빠지거나 겹친 구역이 없어야 한다)');
    });

    await r.step('1단계 칩을 누르면 그 구역만 남고, 다시 누르면 풀린다', async () => {
      await chip('sale').click();
      await p.waitForFunction(() => location.search.includes('area=sale'), null, { timeout: 15000 });
      const total = Number((await p.locator('text=/총 [\\d,]+ 건/').first().innerText()).replace(/[^\d]/g, ''));
      const chipN = Number((await chip('sale').innerText()).replace(/[^\d]/g, ''));
      L.assertEq(total, chipN, '「판매·입금」 목록 건수 = 칩의 숫자');
      await chip('sale').click();
      await p.waitForFunction(() => !location.search.includes('area='), null, { timeout: 15000 });
    });

    await r.step('2단계 구역을 고르면 「컬럼 전체」 목록이 줄어든다', async () => {
      const all = (await openOptions('컬럼')).length;
      await chip('dereg').click();
      await p.waitForFunction(() => location.search.includes('area=dereg'), null, { timeout: 15000 });
      await p.waitForTimeout(800);
      const few = (await openOptions('컬럼')).length;
      if (!(few > 0 && few < all)) throw new Error(`컬럼 목록이 안 줄었다: 전체 ${all} → 말소 ${few}`);
      await chip('all').click();
      await p.waitForFunction(() => !location.search.includes('area='), null, { timeout: 15000 });
      await p.waitForTimeout(800);
    });

    // 🇰🇷 영어로 남은 이름 — 드롭박스 4개
    for (const [ph, what] of [['액션', '액션'], ['대상', '대상'], ['컬럼', '컬럼']]) {
      await r.step(`🇰🇷 「${what} 전체」 목록에 영어로만 된 이름이 없다`, async () => {
        const opts = await openOptions(ph);
        if (opts.length < 3) throw new Error(`목록이 비었다(${opts.length}개) — 콤보가 안 열렸다`);
        const bad = englishOnly(opts);
        if (bad.length) throw new Error(`한글 이름 없는 ${what} ${bad.length}개: ${bad.slice(0, 12).join(' | ')} — config/column_labels.php 에 추가`);
      });
    }

    await r.step('🇰🇷 목록의 대상·액션·컬럼 칸에 영어로만 된 이름이 없다(구역마다 첫 쪽)', async () => {
      const bad = new Set();
      for (const key of ['basic', 'purchase', 'dereg', 'sale', 'export', 'settle', 'buyer', 'people', 'etc']) {
        await p.goto(`${L.BASE}/admin/audit-logs?area=${key}&perPage=100`, { waitUntil: 'networkidle', timeout: 120000 });
        const cells = await p.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).flatMap((tr) => {
          const td = tr.children;
          // 대상(3번째)은 「차량 00가1131」·「board 요청 #83」처럼 이름 뒤에 번호가 붙는다 — 칸 전체에 한글이 있으면 된다.
          const target = td[2] ? (td[2].innerText || '').trim() : '';
          return [target, td[3] ? (td[3].innerText || '').trim() : '', td[4] ? (td[4].innerText || '').trim() : ''];
        }));
        englishOnly(cells).filter((s) => !/^#?\d+$/.test(s)).forEach((s) => bad.add(`${key}: ${s}`));
      }
      if (bad.size) throw new Error(`영어로만 보이는 칸 ${bad.size}종: ${[...bad].slice(0, 15).join(' | ')} — config/column_labels.php 에 추가`);
    });

    await r.step('3단계 액션을 치면서 고르고 [조회] — 고르는 순간엔 요청이 없다', async () => {
      await p.goto(`${L.BASE}/admin/audit-logs`, { waitUntil: 'networkidle', timeout: 120000 });
      const posts = [];
      const onReq = (req) => { if (req.method() === 'POST' && /livewire/.test(req.url())) posts.push(req.url()); };
      await combo('액션').click();
      await combo('액션').fill('수정');
      await p.waitForTimeout(500);
      p.on('request', onReq);
      await p.locator('button:visible', { hasText: /^수정$/ }).first().click();
      await p.waitForTimeout(1200);
      p.off('request', onReq);
      L.assertEq(posts.length, 0, '고르기만 했을 때 서버 요청 수');
      await p.locator('button.btn-search').first().click();
      await p.waitForFunction(() => location.search.includes('actionFilter=updated'), null, { timeout: 15000 });
      L.assertEq(await combo('액션').inputValue(), '수정', '조회 뒤 칸에 남은 이름');
    });

    await r.step('3단계 「필터 초기화」가 구역까지 푼다', async () => {
      await chip('settle').click();
      await p.waitForFunction(() => location.search.includes('area=settle'), null, { timeout: 15000 });
      await p.locator('button', { hasText: '필터 초기화' }).first().click();
      await p.waitForFunction(() => !location.search.includes('area=') && !location.search.includes('actionFilter='), null, { timeout: 15000 });
    });

    await r.step('4단계 차량 수정 창의 「이 차 변경 기록」 → 그 차 번호로 걸러진 감사 로그', async () => {
      await L.openVehicle(p, '00가1131');
      const link = p.locator('[data-vehicle-history-link]').first();
      await link.waitFor({ state: 'visible', timeout: 20000 });
      await L.expectText(p, '이 차 변경 기록', '링크 문구');
      const href = await link.getAttribute('href');
      if (!/audit-logs\?search=/.test(href || '')) throw new Error(`링크 주소가 다르다: ${href}`);
      await L.closePanel(p);
      await p.goto(href, { waitUntil: 'networkidle', timeout: 120000 });
      await L.expectText(p, '00가1131', '걸러진 목록의 차량번호');
    });

    await r.step('🇰🇷 문서 접근 로그 「서류 종류」 목록에 영어로만 된 이름이 없다', async () => {
      await p.goto(`${L.BASE}/admin/document-access-logs`, { waitUntil: 'networkidle', timeout: 120000 });
      const opts = await p.locator('select[wire\\:model\\.live="typeFilter"] option').allInnerTexts();
      if (opts.length < 10) throw new Error(`서류 종류가 ${opts.length}개뿐이다`);
      const bad = englishOnly(opts);
      if (bad.length) throw new Error(`한글 이름 없는 서류 종류: ${bad.join(' | ')} — DocumentAccessLog::DOCUMENT_TYPES 에 추가`);
      const cells = await p.evaluate(() => Array.from(document.querySelectorAll('table tbody tr .badge')).map((b) => (b.innerText || '').trim()));
      const bad2 = [...new Set(englishOnly(cells))];
      if (bad2.length) throw new Error(`목록에 영어로만 보이는 서류 종류: ${bad2.join(' | ')}`);
    });

    await L.assertNoViolations(p);
  } finally {
    await ctx.close();
  }
  return r.result();
};
