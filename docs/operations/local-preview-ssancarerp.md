# ssancarerp 운영 사본을 로컬 8010 에 띄우기 (미리보기)

> 언제 쓰나 — 로컬 8001 은 차량 90대 샘플이라 **100행 체감 · 관리자 대시보드 그래프 · 포워딩사 상세**처럼
> 데이터가 있어야 보이는 것을 확인할 수 없다(메모리 `reference_local_vs_preview_data`).
> 2026-09-18 9/10 지급분 미리보기, 2026-09-22 패널 섬·그래프 확인에 썼다.
>
> 🚫 운영은 **읽기만** 한다(mysqldump). 🚫 jin 의 8001 · 로컬 `car_erp` 는 건드리지 않는다.
> ⚠️ 사본은 운영 개인정보다 — 확인이 끝나면 **§4 정리**까지 해야 끝난 것이다.

## 1. 운영에서 스냅샷 (읽기 전용)

Claude 의 자동모드는 운영 SSH 를 막는다(분류기 「Production Reads」). **jin 이 auto 모드를 끄고 Claude 에게 시키거나**,
아래를 그대로 붙여넣어 직접 뜬다. 한 SSH 세션에 묶었다(연달아 붙으면 차단 — 메모리 `feedback_prod_ssh_rate_limit`).

```bash
ssh -i ~/.ssh/car_erp_key ubuntu@heymancar.com 'bash -s' <<'EOF'
set -e
cd /var/www/car-erp
awk -F= '/^DB_(USERNAME|PASSWORD|HOST|PORT|DATABASE)=/{k=$1; v=substr($0,index($0,"=")+1); gsub(/^"|"$/,"",v); m[k]=v} END{printf "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n", m["DB_USERNAME"], m["DB_PASSWORD"], m["DB_HOST"], (m["DB_PORT"]==""?"3306":m["DB_PORT"]); print "DBNAME=" m["DB_DATABASE"] > "/tmp/.dbname"}' .env > /tmp/.mycnf
chmod 600 /tmp/.mycnf
DBNAME=$(sed 's/^DBNAME=//' /tmp/.dbname)
mysqldump --defaults-extra-file=/tmp/.mycnf --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF "$DBNAME" | gzip > /tmp/ssancarerp_snap.sql.gz
shred -u /tmp/.mycnf /tmp/.dbname
ls -la /tmp/ssancarerp_snap.sql.gz
EOF

scp -i ~/.ssh/car_erp_key ubuntu@heymancar.com:/tmp/ssancarerp_snap.sql.gz "$HOME/Desktop/ssancarerpDB/ssancarerp_snap.sql.gz"
ssh -i ~/.ssh/car_erp_key ubuntu@heymancar.com 'rm -f /tmp/ssancarerp_snap.sql.gz'
```

- 자격증명은 `.env` 에서 읽어 `/tmp/.mycnf` 에 **파일로만** 두고 바로 `shred` 한다(명령줄에 비밀번호를 안 남긴다).
- `php artisan tinker <파일>` 은 운영에서도 프로세스가 안 죽는다(planB §7-3) — 그래서 쓰지 않는다.

## 2. 로컬에 올리기

```bash
# .env.preview 가 없으면: .env 복사 → APP_ENV=preview · APP_URL=http://localhost:8010 ·
#   DB_DATABASE=car_erp_sep_preview · COMPANY_TEMPLATE_SET=system · MAIL_MAILER=log  (.gitignore 등재)
bash scripts/ops/local-preview-ssancarerp.sh "$HOME/Desktop/ssancarerpDB/ssancarerp_snap.sql.gz"
```

스크립트가 하는 것 — DB 재생성 → 임포트 → **민감정보 제거 · 발송 차단** → 확인 숫자 출력.

| 무엇 | 왜 |
|---|---|
| 주민번호 · 매입처 계좌 · 신분증번호 NULL | 로컬 `APP_KEY` 가 달라 복호화가 깨지고, 로컬에 남길 이유가 없다 |
| 알림톡 enabled/toggle `0` · tmplId/userkey `''` · 텔레그램 · 카모두 · 회사메일 키 `''` | **운영 사본은 설정이 살아 있어 진짜로 나간다**(2026-09-18 실측 — `submitForMonth` 가 알림톡을 쏜다). `canSend()` 가 tmplId·toggle 을 보므로 둘 다 비운다 |
| sessions · cache · cache_locks 비움 | 운영 세션·편집 잠금이 로컬에 남을 이유가 없다 |
| `VEHICLE_DOCS_DISK` 미설정(=public 로컬) | S3 에 **쓰지 않게**. 대신 운영 첨부는 로컬에서 404 — 정상 |

## 3. 띄우기 · 확인

```bash
APP_ENV=preview php artisan serve --port=8010 --host=127.0.0.1
```
`http://localhost:8010` — 로그인은 ssancarerp 운영 계정 그대로(비밀번호 해시는 APP_KEY 와 무관).
`artisan serve` 라 절대 속도는 운영보다 느리다 — **전 대비**로만 본다(브라우저 F12 Local metrics 의 INP).

## 4. 정리 (끝났으면 반드시)

```bash
# 8010 창 Ctrl+C
/c/xampp/mysql/bin/mysql.exe -u root -e "DROP DATABASE car_erp_sep_preview"
rm "$HOME/Desktop/ssancarerpDB/ssancarerp_snap.sql.gz" .env.preview
```
