// 씬 점검 3단계 공용 보조 — 제안 위치 = scripts/scene/check/p3-helpers.js (dev 전용 · master 제외).
// ⚠️ 초안 — 한 번도 실행하지 않았다. lib.js 에 합쳐도 된다(합치면 require 경로만 바꿀 것).
//
// 하는 일
//  - cashLedgerOn(): 지금 회사 세트의 바이어 현금 원장 토글(읽기만 — 🚫 cashtoggle 금지). buyer-account.js 와 같은 쿼리.
//  - addCashReceipt(): 바이어 「현금」 탭에 입금 기재(씬 전용 비고 표식). 현금 원장이 켜진 회사에서
//    판매 잔금 확정·외화 적립이 「남은 현금」을 요구하므로 그 씬이 쓰는 금액만큼만 넣는다(남기면 buyer-account 사전조건이 깨진다).
//  - tmpPdf(): 업로드용 최소 PDF 를 OS 임시 폴더에 만든다(cap/sample-*.pdf 는 레포에 없다).
//  - docsDisk(): 차량 서류 디스크(config filesystems.vehicle_docs_disk)와 드라이버 — s3 면 업로드 단계를 건너뛴다.
//  - watchDialogs(): 브라우저 confirm 문구를 모은다(lib 의 수락 핸들러와 같이 돈다).
//  - saveVehicle(): [수정 저장] + 저장 전 확인 모달(촬영 스크립트와 같은 문구 목록) + 토스트 대기.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const L = require('./lib');

function cashLedgerOn() {
  const set = (L.dbOne("select value from settings where `key` = 'company_template_set'") || {}).value || 'system';
  const on = L.dbOne('select value from settings where `key` = ?', [`buyer_cash_enabled_${set}`]);
  return { set, on: !!on && ['1', 'true'].includes(String(on.value)) };
}

/** 바이어 「현금」 탭 → 입금 기재. buyer-account.js 의 openCashTab 과 같은 조작. */
async function addCashReceipt(page, buyerName, { currency = 'USD', amount, note }) {
  await page.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(buyerName)}`, { waitUntil: 'networkidle', timeout: 120000 });
  await page.waitForTimeout(1200);
  await page.locator('tr', { hasText: buyerName }).first().click();
  await page.waitForTimeout(2000);
  await page.locator('button', { hasText: /^현금$/ }).first().click();
  await page.waitForTimeout(800);
  await page.locator('input[wire\\:model="cash_date"]').fill(L.today().iso);
  await page.locator('select[wire\\:model="cash_currency"]').selectOption(currency);
  await page.locator('input[wire\\:model="cash_amount"]').fill(String(amount));
  await page.locator('input[wire\\:model="cash_note"]').fill(note);
  await L.expectAfter(page, () => page.getByRole('button', { name: '입금 기재' }).click(), '입금을 기재했습니다.', `현금 입금 기재(${note})`);
  await L.assertNoViolations(page);
  const row = L.dbOne('select r.id, r.amount from buyer_cash_receipts r join buyers b on b.id = r.buyer_id where b.name = ? and r.note = ? order by r.id desc', [buyerName, note]);
  if (!row) throw new Error(`DB: 입금 기재 행 없음(${note})`);
  return row;
}

let pdfPath = null;
function tmpPdf() {
  if (pdfPath && fs.existsSync(pdfPath)) return pdfPath;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'scene-check-'));
  pdfPath = path.join(dir, 'scene-sample.pdf');
  // 1쪽짜리 빈 PDF(xref 오프셋은 뷰어가 관대하다 — 서버는 mimes 만 본다)
  const pdf = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
    + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';
  fs.writeFileSync(pdfPath, pdf, 'latin1');
  return pdfPath;
}

/** 차량 서류 디스크 — scripts/scene/check/disk-guard.php(읽기만). 실패하면 s3 로 간주(안전 쪽). */
function docsDisk() {
  try {
    return JSON.parse(execFileSync('php', [path.join(__dirname, 'disk-guard.php')], { cwd: L.ROOT, timeout: 60000 }).toString());
  } catch (e) {
    return { disk: '?', driver: 's3', error: String(e.message).slice(0, 120) };
  }
}

/** 떠 있는 「통관서류 알람」 판 숨기기 — 촬영 스크립트(ra2·inv_scene·lv_scene)의 hideAlarm 그대로. goto 뒤에 부른다. */
async function hideAlarm(page) {
  await page.evaluate(() => {
    for (const el of document.querySelectorAll('*')) {
      if (el.children.length === 0 && /통관서류 알람/.test(el.textContent || '')) {
        let a = el;
        while (a && getComputedStyle(a).position !== 'fixed') a = a.parentElement;
        if (a) a.style.display = 'none';
      }
    }
  }).catch(() => {});
}

function watchDialogs(page) {
  const msgs = [];
  page.on('dialog', (d) => msgs.push(d.message()));
  return msgs;
}

/** [수정 저장] → 확인 모달이 뜨면 「확인 후 저장」 등 → 토스트. modalTitle 을 주면 그 창 제목도 본다. */
async function saveVehicle(page, stepName, { modalTitle = null, toast = '차량 정보가 수정됐습니다.' } = {}) {
  // 저장 토스트는 몇 초 뒤 사라진다 — 확인 창을 기다리는 동안 놓치지 않게 누르기 전부터 지켜본다(11-16)
  const seenToast = toast
    ? page.waitForFunction((t) => document.body && document.body.innerText.includes(t), toast, { timeout: 40000, polling: 200 }).then(() => true, () => false)
    : Promise.resolve(true);
  await page.getByRole('button', { name: '수정 저장' }).first().click();
  // 🔀 11-16 — 1.5초씩 세 번만 보던 것을 「창이 뜨면 누르고, 조용해질 때까지」로. 31장을 이어 돌리면 서버가 느려져 창이 늦게 뜬다.
  const sawModal = (await confirmUntilSettled(page, { modalTitle, stepName })) > 0;
  if (modalTitle && !sawModal) throw new Error(`[${stepName}] 「${modalTitle}」 확인 창이 안 떴다`);
  if (toast && !(await seenToast)) throw new Error(`[${stepName} 저장 토스트] 화면에 「${toast}」 없음`);
  await L.assertNoViolations(page);
}

/**
 * 저장 뒤 확인 창(판매 정보 확인·문서 확인 등)이 뜨면 눌러 넘기고, 창도 「저장 중…」도 없이 quietMs 가 지나면 끝낸다(최대 maxMs).
 * 첫 창이 뜨면 modalTitle 을 확인한다. 누른 횟수를 돌려준다.
 */
async function confirmUntilSettled(page, { modalTitle = null, stepName = '', maxMs = 15000, quietMs = 2500 } = {}) {
  const start = Date.now();
  let last = Date.now();
  let clicked = 0;
  while (Date.now() - start < maxMs) {
    // 누른 직후 그 버튼은 disabled(data-loading)로 남아 있다 — 잠긴 건 「저장 중」으로 보고 다시 누르지 않는다(11-16 60초 멈춤)
    const x = page.locator('button:visible:not([disabled])', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ });
    const loading = await page.locator('button:visible[disabled][data-loading]').count();
    if (loading) { last = Date.now(); await page.waitForTimeout(300); continue; }
    if (await x.count()) {
      if (modalTitle && clicked === 0) await L.expectText(page, modalTitle, `${stepName} 확인 창 제목`);
      await x.first().click();
      clicked++;
      last = Date.now();
      await page.waitForTimeout(800);
      continue;
    }
    const busy = await page.locator('button:visible', { hasText: /저장 중/ }).count();
    if (busy) last = Date.now();
    else if (Date.now() - last > quietMs) break;
    await page.waitForTimeout(300);
  }

  return clicked;
}

module.exports = { cashLedgerOn, addCashReceipt, tmpPdf, docsDisk, hideAlarm, watchDialogs, saveVehicle, confirmUntilSettled };
