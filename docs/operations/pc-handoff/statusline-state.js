#!/usr/bin/env node
/**
 * statusline-state.js — 상태 뱃지 래퍼
 *
 * 기존 claude-dashboard 상태줄을 그대로 실행하고, 그 앞에
 * 세션 상태 뱃지(🟢 진행중 / 🟡 결정 필요 / 🔴 실패 / ✅ 완료 / ⚪ 대기)를 덧붙인다.
 *
 * 판정은 transcript_path(JSONL)의 마지막 메시지를 읽어 추론한다.
 * 어떤 오류가 나도 상태줄이 깨지지 않도록 전부 try/catch로 감싼다.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

// 래핑할 기존 대시보드 (원래 statusLine.command)
const DASHBOARD = 'C:/xampp/htdocs/claude-dashboard-main/claude-dashboard-main/dist/index.js';

// ANSI
const R = '\x1b[0m';
const GREEN = '\x1b[32m';
const YELLOW = '\x1b[33m';
const RED = '\x1b[31m';
const DIM = '\x1b[2m';

const BADGE = {
  working:  GREEN  + '🟢 진행중' + R,
  decide:   YELLOW + '🟡 결정 필요' + R,
  failed:   RED    + '🔴 실패' + R,
  done:     GREEN  + '✅ 완료' + R,
  idle:     DIM    + '⚪ 대기' + R,
};

// --- stdin(JSON) 읽기 -------------------------------------------------------
let raw = '';
try {
  raw = fs.readFileSync(0, 'utf8');
} catch (_) {
  raw = '';
}

let input = {};
try {
  input = JSON.parse(raw);
} catch (_) {
  input = {};
}

// --- transcript 꼬리 읽기 ----------------------------------------------------
function readTail(path, maxBytes) {
  const stat = fs.statSync(path);
  const size = stat.size;
  const readSize = Math.min(size, maxBytes);
  const fd = fs.openSync(path, 'r');
  try {
    const buf = Buffer.alloc(readSize);
    fs.readSync(fd, buf, 0, readSize, size - readSize);
    let text = buf.toString('utf8');
    // 앞이 잘렸으면 첫 부분 라인 버림
    if (size > readSize) {
      const nl = text.indexOf('\n');
      if (nl >= 0) text = text.slice(nl + 1);
    }
    return text;
  } finally {
    fs.closeSync(fd);
  }
}

// 어시스턴트 엔트리 → 텍스트/툴사용 여부
function assistantText(entry) {
  const content = entry && entry.message && entry.message.content;
  let text = '';
  let hasTool = false;
  if (Array.isArray(content)) {
    for (const b of content) {
      if (!b || !b.type) continue;
      if (b.type === 'text' && typeof b.text === 'string') text += b.text;
      if (b.type === 'tool_use') hasTool = true;
    }
  } else if (typeof content === 'string') {
    text = content;
  }
  return { text, hasTool };
}

// user 엔트리가 tool_result(중간 진행)인지
function isToolResult(entry) {
  const content = entry && entry.message && entry.message.content;
  if (Array.isArray(content)) {
    return content.some((b) => b && b.type === 'tool_result');
  }
  return false;
}

function detectState(transcriptPath) {
  if (!transcriptPath || !fs.existsSync(transcriptPath)) return 'idle';

  const tail = readTail(transcriptPath, 64 * 1024);
  const lines = tail.split('\n').filter((l) => l.trim() !== '');

  // 뒤에서부터 user/assistant 엔트리 탐색
  let last = null;
  for (let i = lines.length - 1; i >= 0; i--) {
    let obj;
    try { obj = JSON.parse(lines[i]); } catch (_) { continue; }
    if (!obj || (obj.type !== 'user' && obj.type !== 'assistant')) continue;
    last = obj;
    break;
  }

  if (!last) return 'idle';

  // 마지막이 user → 어시스턴트가 아직 응답 전(신규 입력) 또는 툴 결과 반환(중간) = 진행중
  if (last.type === 'user') {
    return 'working';
  }

  // 마지막이 assistant
  const { text, hasTool } = assistantText(last);
  const trimmed = text.trim();

  // 텍스트 없이 툴만 = 아직 작업 중
  if (hasTool && trimmed === '') return 'working';

  // 텍스트 마커 판정 (줄 시작 기준, 대소문자 무시)
  if (/(^|\n)\s*failed:/i.test(text)) return 'failed';
  if (/(^|\n)\s*needs input:/i.test(text)) return 'decide';
  if (/(^|\n)\s*result:/i.test(text)) return 'done';

  // 마지막 비어있지 않은 줄이 '?'로 끝나면 사용자에게 질문 = 결정 필요
  const nonEmpty = trimmed.split('\n').map((s) => s.trim()).filter(Boolean);
  const lastLine = nonEmpty[nonEmpty.length - 1] || '';
  if (lastLine.endsWith('?')) return 'decide';

  return 'idle';
}

// --- 상태 판정 --------------------------------------------------------------
let state = 'idle';
try {
  state = detectState(input.transcript_path);
} catch (_) {
  state = 'idle';
}
const badge = BADGE[state] || BADGE.idle;

// --- 기존 대시보드 실행 후 앞에 뱃지 붙이기 ---------------------------------
let dashOut = '';
try {
  const res = spawnSync('node', [DASHBOARD], { input: raw, encoding: 'utf8' });
  dashOut = (res.stdout || '').replace(/\n$/, '');
} catch (_) {
  dashOut = '';
}

// --- Codex 진행줄 (codex_status.txt 있을 때만, 없으면 무해하게 생략) ---------
function codexLine() {
  try {
    const CODEX_STATUS = path.join(os.homedir(), '.claude', 'codex_status.txt');
    if (!fs.existsSync(CODEX_STATUS)) return '';
    const txt = fs.readFileSync(CODEX_STATUS, 'utf8').trim();
    if (!txt) return '';
    const ageMin = Math.floor((Date.now() - fs.statSync(CODEX_STATUS).mtimeMs) / 60000);
    let age;
    if (ageMin < 1) age = '방금';
    else if (ageMin < 60) age = ageMin + '분 전';
    else if (ageMin < 1440) age = Math.floor(ageMin / 60) + '시간 전';
    else age = Math.floor(ageMin / 1440) + '일 전';
    const stale = ageMin >= 30;
    const color = stale ? '\x1b[90m' : '\x1b[36m';
    const idle = stale ? ' · 유휴?' : '';
    return '\n' + color + '🤖 Codex: ' + txt + ' (' + age + idle + ')' + R;
  } catch (_) {
    return '';
  }
}

const codex = codexLine();

if (dashOut) {
  process.stdout.write(badge + '  ' + dashOut + codex);
} else {
  process.stdout.write(badge + codex);
}
