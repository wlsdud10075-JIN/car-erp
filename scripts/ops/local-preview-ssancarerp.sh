#!/usr/bin/env bash
# ssancarerp 운영 사본을 로컬 8010 에 띄우기 — 로컬 단계(임포트 · 민감정보 제거 · 발송 차단). 2026-09-22.
#   사용: bash scripts/ops/local-preview-ssancarerp.sh <ssancarerp_snap.sql.gz>
#   선행: 운영에서 스냅샷을 뜬 파일 (docs/operations/local-preview-ssancarerp.md §1). 운영은 읽기만 한다.
#   결과: 로컬 MySQL `car_erp_sep_preview` + `.env.preview` 로 `php artisan serve --port=8010` 준비 완료.
#   🚫 jin 의 8001 · 로컬 `car_erp` DB 는 건드리지 않는다.
set -euo pipefail
DUMP="${1:?스냅샷 .sql.gz 경로}"
MYSQL="/c/xampp/mysql/bin/mysql.exe"
DB="car_erp_sep_preview"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

[ -f "$ROOT/.env.preview" ] || { echo ".env.preview 가 없다 — .env 를 복사해 APP_ENV=preview · APP_URL=8010 · DB_DATABASE=$DB · COMPANY_TEMPLATE_SET=system · MAIL_MAILER=log 로 고쳐 만들 것"; exit 1; }
grep -q "^DB_DATABASE=$DB$" "$ROOT/.env.preview" || { echo ".env.preview 의 DB_DATABASE 가 $DB 가 아니다"; exit 1; }

echo "① DB 재생성 $DB"
"$MYSQL" -u root -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "② 임포트 ($(du -h "$DUMP" | cut -f1))"
gzip -dc "$DUMP" | "$MYSQL" -u root "$DB"

echo "③ 민감정보 제거 · 발송 차단 · 세션/캐시 비움"
"$MYSQL" -u root "$DB" <<'SQL'
-- APP_KEY 가 다르면 복호화가 깨지고(주민번호·계좌·신분증), 로컬에 남길 이유도 없다 → 비운다
UPDATE vehicles SET purchase_seller_account = NULL, purchase_fee_account = NULL, nice_reg_owner_rrn = NULL;
UPDATE consignees SET id_value = NULL;
-- 알림톡 · 텔레그램 · 카모두 · 회사메일 — 켜짐/토글/템플릿/키 전부 끔 (canSend() 가 tmplId 와 toggle 을 본다)
UPDATE settings SET value = '0' WHERE `key` LIKE 'alimtalk_enabled_%' OR `key` LIKE 'alimtalk_toggle_%' OR `key` = 'telegram_enabled';
UPDATE settings SET value = ''  WHERE `key` LIKE 'alimtalk_tmpl_%' OR `key` LIKE 'alimtalk_userkey_%' OR `key` LIKE 'alimtalk_userid_%'
                                   OR `key` IN ('telegram_bot_token', 'telegram_chat_id', 'carmodoo_passwd', 'company_mail_password');
-- 운영 세션 · 캐시(편집 잠금 등)는 여기서 무의미하다
TRUNCATE TABLE sessions; TRUNCATE TABLE cache; TRUNCATE TABLE cache_locks;
SQL

echo "④ 확인"
"$MYSQL" -u root "$DB" -e "SELECT (SELECT COUNT(*) FROM vehicles) AS vehicles, (SELECT COUNT(*) FROM vehicles WHERE nice_reg_owner_rrn IS NOT NULL) AS rrn_left, (SELECT COUNT(*) FROM settings WHERE (\`key\` LIKE 'alimtalk_toggle_%' OR \`key\` LIKE 'alimtalk_enabled_%') AND value <> '0') AS alimtalk_on, (SELECT COUNT(*) FROM settings WHERE \`key\` LIKE 'alimtalk_tmpl_%' AND value <> '') AS tmpl_left;"

cat <<MSG

준비 끝. 띄우기(별도 창):
  cd "$ROOT" && APP_ENV=preview php artisan serve --port=8010 --host=127.0.0.1
접속 http://localhost:8010  — 로그인은 ssancarerp 운영 계정 그대로(비밀번호 해시는 APP_KEY 무관).
끝나면 정리: 8010 종료 · "$MYSQL" -u root -e "DROP DATABASE \`$DB\`" · 스냅샷 파일 삭제 · .env.preview 삭제
MSG
