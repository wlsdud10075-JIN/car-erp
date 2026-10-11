# 월정산 승인요청 알림톡 재발송 — v3 (2026-10-11 준비)

> jin: *「월요일에 새롭게 바뀐 걸로 9월정산(10/10 지급분) 알림톡을 다시 보내 달라 하면 바뀐 내용으로 다시 보낼 수 있도록 준비해줘」*

## 무엇이 바뀌나 (문구는 그대로, 숫자·링크만)
- 템플릿 `erp_payout_request` 본문·버튼은 BizM 승인본 그대로(「월배치」 표기 포함, 재심사 안 받음).
- 카드형 변수: 총액 = `total_payout`(정산·조정 총액, 종전) · **회사이익 = 급여 차감 후**(`profitStats()['company_profit_after_payroll']`) · 건수 · 제출자.
- 버튼 링크 = 서명 URL(5일) → **v3 승인 화면**(합계 띠 · 사람 카드 · 결재선 · 인센티브 추가 · 의견).

## 방법 A — 화면 버튼 (제출자: 관리·업무관리자)
월정산 메뉴 → 승인 대기 카드 → **「📨 승인요청 재전송」**. 현재 차례인 승인자 전원(종전 사다리 = 최고관리자 전원, 결재선 모드 = 그 칸의 한 사람)에게 간다. 10분 쿨다운.
⚠️ 시스템관리자(super)·최고관리자는 못 누른다(2026-10-07 결정) — jin 이면 방법 B.

## 방법 B — 서버에서 직접 (Claude, jin 지시 때)
```bash
# 전원(현재 차례)에게 — 화면 버튼과 같은 경로. ssancarerp 예:
ssh -i ~/.ssh/car_erp_key ubuntu@heymancar.com 'cd /var/www/car-erp && cat > /tmp/rs.php <<'"'"'EOF'"'"'
<?php
require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$b = App\Models\SettlementPayoutBatch::where("month", "2026-09")->where("status", "pending")->orderByDesc("id")->firstOrFail();
echo "batch #{$b->id} approvers=", $b->currentApprovers()->pluck("name")->implode(","), "\n";
$b->notifyPayoutRequest(); App\Models\AuditLog::recordEvent($b, "payout_request_resent");
echo "sent ", $b->fresh()->request_notified_at, "\n";
EOF
php /tmp/rs.php; rm -f /tmp/rs.php'
```
- **한 번호에만**(확인용)은 이번 세션 scratch 스크립트 `sendjin.php` 형태 — `BizmAlimtalkService::active()->send('erp_payout_request', $phone, $vars, ['user_id'=>$u->id], [['name'=>'승인/반려 바로가기','url'=>$b->approvalLinkFor($u)]])`. ⚠️ 2026-10-11 실수: 「최고관리자」 계정(#2) 번호로 먼저 보냈다(전달됨 — jin 번호가 아니었다). **jin 계정 = #1 시스템관리자(wlsdud10074@naver.com)**. 단건 확인용은 **수신 번호를 jin 에게 먼저 확인**하고 보낼 것. 정정 발송 = ssancar #5·heyman #4 각 1건(#1 번호).
- 발송 결과는 월정산 카드의 「발송성공/발송실패」 뱃지와 `alimtalk_logs` 로 확인.

## 전제
- ssancarerp 9월 월정산 #5 가 **아직 승인 대기**여야 한다(승인·반려되면 재발송 의미 없음 — 반려 뒤 다시 제출하면 제출 시 자동 발송).
- 직급(부장/전무/대표)을 지정해도 **이미 올라간 #5 는 종전 사다리**(steps 없음) — 결재선 모드는 다음 제출부터.
