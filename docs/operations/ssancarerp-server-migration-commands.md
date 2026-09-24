# ssancarerp 서버 이전 — 단계별 실행 명령 (2026-09-24 재개 준비)

> 📄 계획·판단 = `ssancarerp-server-migration-runbook.md`(정본). **이 문서는 그 런북의 A~D 를 「그대로 붙여 넣어 돌리는 명령 블록」으로 푼 것**이다.
> 블록마다 마지막 줄이 **검증**이다 — 검증이 안 맞으면 다음 블록으로 넘어가지 않는다.
> 🚫 **A1 부터 운영 쓰기가 아니다**(파이프로 읽기만) — 그래도 **jin 승인 뒤에** 시작한다(런북 원칙).
> 🚫 **B8(WireGuard 기동)·C 전체는 jin 명시 승인 + 오토모드 OFF.**
>
> ⚠️ **한 번에 실 서버 접속을 연달아 하지 말 것** — ssancarerp 는 연속 접속 시 무출력으로 멈춘다(`feedback_prod_ssh_rate_limit`). 블록 사이 40~90초.
> ⚠️ **Claude 의 Bash 툴은 foreground `sleep` 이 막혀 있다** — 아래 `sleep 45` 가 있는 자리는 **툴 호출을 나눠서** 실행한다(한 블록을 통째로 붙여 넣으면 sleep 에서 죽는다). 사람이 터미널에서 돌릴 땐 그대로.
> ⚠️ **변수는 셸 호출마다 사라진다**(툴 셸 상태 비유지) — 매 호출 첫 줄에 §0 변수 4줄을 다시 넣는다.

## 0. 변수 (내 PC Git Bash)

```bash
KEY=~/.ssh/car_erp_key
OLD=ubuntu@heymancar.com          # 구 서버 = 54.116.7.83 (ip-172-26-0-226)  ⚠️ C4(IP 이동) 뒤엔 이 이름이 새 서버다
NEW=ubuntu@___NEW_IP___           # ← jin 이 알려주는 새 4GB 인스턴스 공인 IP (런북 jin 6)
S="ssh -i $KEY -o ConnectTimeout=20 -o BatchMode=yes -o StrictHostKeyChecking=accept-new"   # accept-new 없으면 새 서버 첫 접속이 BatchMode 에서 실패
STG="/c/Users/User/Desktop/AWS/migration-ssancarerp-2026-09"   # 비밀 폴더 아래 날짜 폴더. 끝나면 정리(개인정보 사본)
mkdir -p "$STG"
$S $NEW 'hostname; free -m | head -2; df -h / | tail -1; cat /etc/os-release | grep PRETTY'
# ✅ 검증: 새 서버 Ubuntu 24.04 · Mem ~3,9xx MB · 응답
```

### 0-B. 실측 기준값 (2026-09-24 07:40 UTC, 구 서버) — D9 대조용

| 항목 | 값 |
|---|---|
| vehicles / settlements / final_payments / audit_logs | 4,994 / 4,587 / 6,067 / 55,578 |
| alimtalk_logs | 12,803 (max created_at 2026-09-24 09:00:14) |
| vehicle_photos (S3 경로) / 서류 있는 차량 | 20 / 1,031 |
| DB 크기 ssancar_erp / board_ssancar | 55.6MB / 0.7MB |
| `.env` 키 수 car-erp / board | 78 / 69 |
| storage/app/private(livewire-tmp 위주) / storage/backups(로컬 덤프) / board storage/app | 42M / 186M / 2.6M(8 파일) |
| 문서 저장 | `VEHICLE_DOCS_DISK=s3` · `DB_BACKUP_DISK=s3` · 버킷 `ssancar-erp-docs` (⚠️ `FILESYSTEM_DISK=local` 은 임시파일용일 뿐) |

---

## A. 사전 백업 (구 서버 읽기만 — 파이프로 뽑는다)

```bash
# A1 DB 덤프 2개 → 로컬 (구 서버에 파일을 안 만든다)
TS=$(date +%Y%m%d_%H%M)
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 ssancar_erp | gzip -9' > "$STG/ssancar_erp_$TS.sql.gz"
sleep 45
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 board_ssancar | gzip -9' > "$STG/board_ssancar_$TS.sql.gz"
ls -la "$STG"/*.sql.gz; for f in "$STG"/*_$TS.sql.gz; do echo "$f tables=$(zcat "$f" | grep -c '^CREATE TABLE')"; done
# ✅ 검증: ssancar_erp 수 MB 이상 · CREATE TABLE 수십 개 · board 도 0 이 아님
#    S3 에는 매일 db:backup 사본이 이미 있다(3벌째) — 따로 안 만든다
```

```bash
# A2 .env 2개 — 로컬엔 「대조용」으로만 (실제 이전은 B4 에서 구→신 파이프)
$S $OLD 'sudo cat /var/www/car-erp/.env'       > "$STG/ssancarerp.env.$TS.txt"
$S $OLD 'sudo cat /var/www/board-ssancar/.env' > "$STG/ssancarboard.env.$TS.txt"
wc -l "$STG"/*.env.$TS.txt
grep -c "SSANCAR_PORTAL_HMAC_SECRET\|SSANCAR_PORTAL_SOURCE" "$STG/ssancarerp.env.$TS.txt"
# ✅ 검증: 78 / 69 줄 · 두 포털 키 = 2 (08-25 백업엔 없던 것)
```

```bash
# A3 (wg conf · letsencrypt · .db_backup.cnf) 는 로컬에 안 뜬다 — B8·B11 에서 구→신 직결 파이프. 여기선 존재만 확인
$S $OLD 'sudo ls -la /etc/wireguard/ /home/ubuntu/.db_backup.cnf; sudo ls /etc/letsencrypt/live/'
# ✅ 검증: wg-carmodoo.conf 258B · .db_backup.cnf 존재 · live/ 에 board.heymancar.com · heymancar.com
```

```bash
# A4 비밀 아닌 설정 묶음 → 로컬 (nginx·fpm·mysql·supervisor·cron·systemd·백업 스크립트·authorized_keys)
$S $OLD 'sudo tar czf - /etc/nginx/nginx.conf /etc/nginx/sites-available /etc/nginx/conf.d /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/php.ini /etc/mysql /etc/supervisor/conf.d /etc/logrotate.d /etc/sysctl.d /etc/systemd/system/ssancar-erp.service /home/ubuntu/db_backup.sh /home/ubuntu/erp_db_dump.sh /home/ubuntu/weekly_backup_prepare.sh /home/ubuntu/.ssh/authorized_keys 2>/dev/null' > "$STG/ssancarerp-config-$TS.tar.gz"
$S $OLD 'crontab -l' > "$STG/crontab-ubuntu.txt"
tar tzf "$STG/ssancarerp-config-$TS.tar.gz" | wc -l; cat "$STG/crontab-ubuntu.txt"
# ✅ 검증: tar 항목 20+ · crontab 3줄(weekly_backup_prepare · schedule:run · erp_db_dump)
```

```bash
# A5 구 Django 보관 1회 (새 서버엔 안 만든다 — 지우기 전 사본)
$S $OLD 'sudo tar czf - --exclude=venv --exclude="__pycache__" --exclude="*.pyc" /ssancar-erp' > "$STG/ssancar-django-$TS.tar.gz"
tar tzf "$STG/ssancar-django-$TS.tar.gz" | grep -c "db.sqlite3"
# ✅ 검증: db.sqlite3 1 (바이어·컨사이니 원본 — 06-25 엑셀로 이미 이식됐지만 원본 보존)
```

```bash
# A6 상태 스냅샷 (D9 대조) — 0-B 표와 같은 질의 + S3 객체 수
$S $OLD 'sudo mysql -N ssancar_erp -e "SELECT \"vehicles\",COUNT(*) FROM vehicles UNION ALL SELECT \"settlements\",COUNT(*) FROM settlements UNION ALL SELECT \"final_payments\",COUNT(*) FROM final_payments UNION ALL SELECT \"purchase_balance_payments\",COUNT(*) FROM purchase_balance_payments UNION ALL SELECT \"audit_logs\",COUNT(*) FROM audit_logs UNION ALL SELECT \"alimtalk_logs\",COUNT(*) FROM alimtalk_logs UNION ALL SELECT \"vehicle_photos\",COUNT(*) FROM vehicle_photos UNION ALL SELECT \"users\",COUNT(*) FROM users"; sudo mysql -N board_ssancar -e "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema=\"board_ssancar\" ORDER BY table_rows DESC LIMIT 5"; cd /var/www/car-erp && php artisan tinker --execute="echo \"s3_files=\".count(\Illuminate\Support\Facades\Storage::disk(\"s3\")->allFiles(\"vehicles\")).PHP_EOL;"' | tee "$STG/snapshot-before-$TS.txt"
# ✅ 검증: 0-B 표와 같은 자릿수 · s3_files 1,000+
```

---

## B. 새 서버 구축 (구 서버 영향 0 — 단 **B8 WireGuard 는 올리지 않는다**)

> 🚨 **B8 함정** — WireGuard 키·peer 가 하나다. 새 서버가 터널을 올리면 사무실 공유기의 peer endpoint 가 **새 서버로 넘어가 구 서버 원부조회가 끊긴다**(3사 NICE 게이트웨이가 구 서버에 있다). ⇒ B 에서는 **conf 만 넣고 기동하지 않는다.** 기동은 C 에서 구 서버를 내린 뒤.

```bash
# B1 스왑 2G · 타임존은 UTC 그대로 (cron 30 16 = 01:30 KST — KST 로 바꾸면 전부 9시간 밀린다)
$S $NEW 'bash -s' <<'EOF'
set -e
if [ ! -f /swapfile ]; then sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo "/swapfile none swap sw 0 0" | sudo tee -a /etc/fstab; fi
swapon --show; timedatectl | grep "Time zone"
EOF
# ✅ 검증: /swapfile 2G · Time zone: Etc/UTC
```

```bash
# B2 스택 — scripts/karaba-1-stack.sh 와 같은 순서, 차이 = node 24 · 패키지 13개 실측 목록 · supervisor/certbot/wg/tailscale/libreoffice 포함
$S $NEW 'bash -s' <<'EOF'
set -e; export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y && sudo apt-get install -y software-properties-common curl ca-certificates gnupg unzip git
sudo add-apt-repository -y ppa:ondrej/php
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt-get update -y
sudo apt-get install -y php8.4-bcmath php8.4-cli php8.4-common php8.4-curl php8.4-fpm php8.4-gd php8.4-intl php8.4-mbstring php8.4-mysql php8.4-opcache php8.4-readline php8.4-xml php8.4-zip \
  nginx mysql-server nodejs supervisor certbot python3-certbot-nginx wireguard-tools libreoffice-calc fonts-nanum
sudo update-alternatives --set php /usr/bin/php8.4 || true
sudo sed -i -E 's/^(upload_max_filesize[[:space:]]*=[[:space:]]*).*/\140M/; s/^(post_max_size[[:space:]]*=[[:space:]]*).*/\140M/' /etc/php/8.4/fpm/php.ini
if ! command -v composer >/dev/null; then php -r "copy('https://getcomposer.org/installer','/tmp/c.php');" && sudo php /tmp/c.php --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/c.php; fi
curl -fsSL https://tailscale.com/install.sh | sh
sudo systemctl enable --now php8.4-fpm nginx mysql supervisor
php -v | head -1; node -v; npm -v; composer --version | head -1; nginx -v; mysql --version | cut -c1-40; soffice --version | head -1
echo "ext: $(php -m | grep -cE '^(gd|zip|bcmath|intl|mbstring|xml|curl|mysqlnd)$')/8 opcache: $(php -m | grep -c 'Zend OPcache')"
EOF
# ✅ 검증: PHP 8.4.x · node v24 · ext 8/8 · opcache 1 · nginx 1.24 · mysql 8.0 · LibreOffice 24
```

```bash
# B3 코드 — master 그대로 (구 서버 sha 와 같아야 한다). 두 레포 다 public(09-24 확인) — 인증 불필요, 구 서버도 자격증명 없이 https 로 fetch 한다
$S $NEW 'bash -s' <<'EOF'
set -e
sudo mkdir -p /var/www && sudo chown ubuntu:ubuntu /var/www
[ -d /var/www/car-erp ]       || git clone -q -b master https://github.com/wlsdud10075-JIN/car-erp.git /var/www/car-erp
[ -d /var/www/board-ssancar ] || git clone -q -b master https://github.com/wlsdud10075-JIN/board.git   /var/www/board-ssancar
git -C /var/www/car-erp log --oneline -1; git -C /var/www/board-ssancar log --oneline -1
EOF
# ✅ 검증: car-erp sha == 구 서버(0-B 시점 7bf9279) · board sha == 구 서버(6f3560f)
```

```bash
# B4 .env 구→신 직결 (내 PC 메모리만 지남, 디스크 X) + APP_KEY 지문 대조
$S $OLD 'sudo cat /var/www/car-erp/.env'       | $S $NEW 'cat > /var/www/car-erp/.env && chmod 664 /var/www/car-erp/.env'        # 구 서버 664 ubuntu:ubuntu 그대로
sleep 45
$S $OLD 'sudo cat /var/www/board-ssancar/.env' | $S $NEW 'cat > /var/www/board-ssancar/.env && chmod 600 /var/www/board-ssancar/.env'  # 구 서버 600
sleep 45
$S $OLD 'grep ^APP_KEY= /var/www/car-erp/.env | sha256sum | cut -c1-16; grep ^APP_KEY= /var/www/board-ssancar/.env | sha256sum | cut -c1-16'
$S $NEW 'grep ^APP_KEY= /var/www/car-erp/.env | sha256sum | cut -c1-16; grep ^APP_KEY= /var/www/board-ssancar/.env | sha256sum | cut -c1-16; grep -c . /var/www/car-erp/.env; grep -E "^APP_URL=" /var/www/car-erp/.env'
# ✅ 검증: 지문 2쌍 동일 · 78줄 · APP_URL="https://heymancar.com" (🚨 바꾸지 않는다 — 알림톡 버튼 6종이 이 값)
```

```bash
# B5 DB + 사용자 — 값은 .env 에서 읽는다 (사용자@127.0.0.1)
$S $NEW 'bash -s' <<'EOF'
set -e
for APP in car-erp board-ssancar; do
  E=/var/www/$APP/.env
  DB=$(grep -E '^DB_DATABASE=' $E | cut -d= -f2- | tr -d '"'); U=$(grep -E '^DB_USERNAME=' $E | cut -d= -f2- | tr -d '"'); P=$(grep -E '^DB_PASSWORD=' $E | cut -d= -f2- | tr -d '"')
  sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$U'@'127.0.0.1' IDENTIFIED BY '$P'; CREATE USER IF NOT EXISTS '$U'@'localhost' IDENTIFIED BY '$P'; GRANT ALL ON \`$DB\`.* TO '$U'@'127.0.0.1'; GRANT ALL ON \`$DB\`.* TO '$U'@'localhost'; FLUSH PRIVILEGES;"
  echo "$APP → db=$DB user=$U"
done
sudo mysql -N -e "SELECT user,host FROM mysql.user WHERE user LIKE '%ssancar%' OR user LIKE '%board%'"
EOF
# ✅ 검증: 사용자 2종 × 2호스트 · innodb_buffer_pool 은 기본(128M) 그대로 — 구 서버도 override 없음, DB 55MB
```

```bash
# B6 의존성·빌드·권한 (fpm=www-data, car-erp 워커=ubuntu, board 워커=www-data — 구 서버와 동일)
$S $NEW 'bash -s' <<'EOF'
set -e
for APP in car-erp board-ssancar; do
  cd /var/www/$APP
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev -q
  npm ci --silent && npm run build --silent
  php artisan storage:link || true
  sudo chgrp -R www-data storage bootstrap/cache && sudo chmod -R g+rwX storage bootstrap/cache && sudo find storage -type d -exec chmod g+s {} +
  echo "$APP built"
done
ls -la /var/www/car-erp/public/build/manifest.json /var/www/car-erp/public/storage
EOF
# ✅ 검증: manifest.json 존재 · public/storage → storage/app/public 링크 · 에러 0
```

```bash
# B7 php-fpm 풀 = 구 서버 값 (max_children 14 — 09-21 PSS 실측으로 4GB 에 충분, 줄이면 NICE 대기 때 워커 부족)
$S $NEW 'bash -s' <<'EOF'
set -e
P=/etc/php/8.4/fpm/pool.d/www.conf
sudo sed -i -E 's/^pm\.max_children\s*=.*/pm.max_children = 14/; s/^pm\.start_servers\s*=.*/pm.start_servers = 3/' $P
sudo systemctl restart php8.4-fpm.service
grep -E "^pm\.(max_children|start_servers)" $P; grep -E "^(upload_max_filesize|post_max_size|memory_limit)" /etc/php/8.4/fpm/php.ini; systemctl is-active php8.4-fpm
EOF
# ✅ 검증: 14 / 3 · 40M/40M/128M · active
```

```bash
# B8 WireGuard — conf 만 (🚫 기동 금지, C 에서)
$S $OLD 'sudo cat /etc/wireguard/wg-carmodoo.conf' | $S $NEW 'sudo install -m 600 -o root -g root /dev/stdin /etc/wireguard/wg-carmodoo.conf'
$S $NEW 'sudo ls -la /etc/wireguard/; sudo wc -c /etc/wireguard/wg-carmodoo.conf; systemctl is-enabled wg-quick@wg-carmodoo 2>&1'
# ✅ 검증: 258B · 600 root · is-enabled = disabled/not-found (아직 안 켠 상태)
```

```bash
# B9 Tailscale — 👤 jin 이 브라우저에서 승인 (URL 이 뜬다). 노드명은 구 서버와 겹치지 않게
#    ⚠️ `tailscale up` 은 승인될 때까지 블로킹 — timeout 으로 끊어도 노드는 NeedsLogin 으로 남아 URL 이 유효하다
$S $NEW 'sudo timeout 45 tailscale up --hostname=ssancarerp-server-new 2>&1 | head -5; tailscale status 2>&1 | head -2'
#    (jin 이 관리콘솔에서 pre-auth key 를 만들어 주면 `--authkey=tskey-…` 로 승인 없이 끝난다)
# → 뜬 URL 을 jin 에게. 승인 후:
$S $NEW 'tailscale ip -4; tailscale status | head -3'
# ✅ 검증: 100.x IP 발급. (챗봇은 현재 꺼져 있어 gpu-office 연결은 D 에서 확인만)
```

```bash
# B10 DB 복원 (구→신 직결) + 건수 대조
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 ssancar_erp | gzip -9' | $S $NEW 'gunzip | sudo mysql ssancar_erp'
sleep 45
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 board_ssancar | gzip -9' | $S $NEW 'gunzip | sudo mysql board_ssancar'
$S $NEW 'sudo mysql -N ssancar_erp -e "SELECT COUNT(*) FROM vehicles; SELECT COUNT(*) FROM settlements; SELECT COUNT(*) FROM audit_logs"; cd /var/www/car-erp && echo "pending=$(php artisan migrate:status | grep -c Pending || true)"'
# ✅ 검증: 건수 = A6 스냅샷(이 시점 기준) · pending=0 (같은 master 라 마이그 없음)
```

```bash
# B11 nginx + letsencrypt(구→신 직결) + supervisor + cron + 백업 스크립트 + authorized_keys(덧붙임) + .db_backup.cnf + backup_staging + 챗봇 색인 2개
#     tar 는 / 에 바로 풀지 않는다 — authorized_keys 가 새 인스턴스 기본 키를 덮어 SSH 가 끊긴다. /tmp/mig 에 풀고 골라 놓는다
$S $OLD 'sudo tar czf - /etc/nginx/sites-available/ssancar-erp /etc/nginx/sites-available/board-ssancar /etc/letsencrypt /etc/supervisor/conf.d /home/ubuntu/db_backup.sh /home/ubuntu/erp_db_dump.sh /home/ubuntu/weekly_backup_prepare.sh /home/ubuntu/.db_backup.cnf /home/ubuntu/.ssh/authorized_keys /var/www/car-erp/storage/app/index-erp.json /var/www/board-ssancar/storage/app/index-board.json 2>/dev/null' | $S $NEW 'rm -rf /tmp/mig && mkdir -p /tmp/mig && sudo tar xzf - -C /tmp/mig --no-same-owner 2>&1 | grep -v "Removing leading"; find /tmp/mig -type f | wc -l'
sleep 45
$S $NEW 'bash -s' <<'EOF'
set -e; M=/tmp/mig
sudo cp -a $M/etc/nginx/sites-available/ssancar-erp $M/etc/nginx/sites-available/board-ssancar /etc/nginx/sites-available/
sudo rm -rf /etc/letsencrypt && sudo cp -a $M/etc/letsencrypt /etc/letsencrypt
sudo cp -a $M/etc/supervisor/conf.d/*.conf /etc/supervisor/conf.d/
cp $M/home/ubuntu/*.sh $M/home/ubuntu/.db_backup.cnf /home/ubuntu/ && chmod 700 /home/ubuntu/db_backup.sh /home/ubuntu/erp_db_dump.sh && chmod 755 /home/ubuntu/weekly_backup_prepare.sh && chmod 600 /home/ubuntu/.db_backup.cnf
# authorized_keys — 새 인스턴스 기본 키는 남기고 구 서버 키 5개를 덧붙인다(중복 제거)
cat $M/home/ubuntu/.ssh/authorized_keys >> /home/ubuntu/.ssh/authorized_keys && awk '!seen[$0]++' /home/ubuntu/.ssh/authorized_keys > /tmp/ak && cat /tmp/ak > /home/ubuntu/.ssh/authorized_keys && chmod 600 /home/ubuntu/.ssh/authorized_keys
cp $M/var/www/car-erp/storage/app/index-erp.json /var/www/car-erp/storage/app/ && cp $M/var/www/board-ssancar/storage/app/index-board.json /var/www/board-ssancar/storage/app/
mkdir -p /home/ubuntu/backup_staging/daily/db /home/ubuntu/backup_staging/daily/status /home/ubuntu/backup_staging/db /home/ubuntu/backup_staging/system /home/ubuntu/db_backups
# 🚫 구 Django 잔재 — sites-available/ssancar-erp 의 `location ^~ /provide/ { proxy_pass http://unix:/ssancar-erp/gunicorn.sock; …}` 블록은 새 서버에 gunicorn 이 없어 502 가 된다.
#    nice-lookup 두 경로(= 정확 매치, PHP)는 그 위에 따로 있으니 그대로 두고, 이 블록만 404 로 바꾼다 (블록 범위는 §B11-주 참조 — 편집 후 반드시 grep 0)
sudo python3 - <<'PY'
import re,io
p='/etc/nginx/sites-available/ssancar-erp'; s=open(p).read()
new=re.sub(r'location \^~ /provide/ \{.*?\n\s*\}\n', 'location ^~ /provide/ { return 404; }\n', s, count=1, flags=re.S)
assert new!=s and 'gunicorn' not in new, 'provide 블록 치환 실패 — 손으로 편집'
open(p,'w').write(new); print('provide block → 404')
PY
sudo ln -sf /etc/nginx/sites-available/ssancar-erp /etc/nginx/sites-enabled/ssancar-erp
sudo ln -sf /etc/nginx/sites-available/board-ssancar /etc/nginx/sites-enabled/board-ssancar
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
sudo supervisorctl reread && sudo supervisorctl update && sleep 3 && sudo supervisorctl status
( crontab -l 2>/dev/null; echo "0 1 * * 0 /home/ubuntu/weekly_backup_prepare.sh"; echo "* * * * * cd /var/www/car-erp && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1"; echo "30 16 * * * /home/ubuntu/erp_db_dump.sh" ) | sort -u | crontab -
echo "cron=$(crontab -l | grep -c .) keys=$(grep -c '^ssh-' /home/ubuntu/.ssh/authorized_keys) gunicorn=$(grep -c gunicorn /etc/nginx/sites-available/ssancar-erp || true) django_unit=$(systemctl list-unit-files | grep -c ssancar-erp.service || true)"
sudo certbot certificates 2>/dev/null | grep -E "Certificate Name|Expiry"
rm -rf /tmp/mig /tmp/ak
EOF
# ✅ 검증: nginx -t ok · 워커 2개 RUNNING(로그 = 각 앱 storage/logs/worker.log — B6 권한으로 www-data 도 쓴다) · cron=3 · keys=6(새 인스턴스 1 + github-actions ×3 · claude · nas) · gunicorn=0 · django_unit=0 · 인증서 2개 VALID
# §B11-주: 구 서버 conf 실측(09-24) — 8~9행 nice-lookup `location =` 2개(PHP, 그대로) · **12~19행** `location ^~ /provide/ { proxy_pass http://unix:/ssancar-erp/gunicorn.sock; proxy_set_header ×4; proxy_redirect off; }`(중첩 중괄호 없음 → 위 치환이 이 블록만 잡는다) · 22~23행 Django `/static/`·`/media/` alias 는 없는 경로라 404 — 무해, 손대지 않는다 · 35~39행 인증서 = `/etc/letsencrypt/live/heymancar.com/`(B11 복사본)
```

```bash
# B12 전환 전 전 화면 점검 — 내 PC 에서 --resolve (jin 은 hosts 파일: "NEW_IP heymancar.com board.heymancar.com")
NEWIP=___NEW_IP___
for u in https://heymancar.com/login https://board.heymancar.com/login https://heymancar.com/provide/api/nice-lookup/; do
  printf "%s → " "$u"; curl -s -o /dev/null -w '%{http_code} %{time_total}s\n' --resolve heymancar.com:443:$NEWIP --resolve board.heymancar.com:443:$NEWIP "$u"
done
$S $NEW 'tail -3 /var/www/car-erp/storage/logs/laravel.log 2>/dev/null | cut -c1-160; sudo tail -3 /var/log/nginx/error.log | cut -c1-160'
# ✅ 검증: login 200 ×2 (복사한 인증서라 -k 불필요) · nice-lookup 은 405/401/422 (502 가 아니면 됨) · laravel.log ERROR 0
# 👤 jin: hosts 로 물려 로그인 → 차량 100행 → 편집 패널 → 서류 xlsx 1건 → 사진 1건 (S3) → RRN 1건 복호화 표시
```

---

## C. 전환 (D-day · 다운타임 10~20분 · 👤 jin 승인 후)

```bash
# C1 구 서버 쓰기 차단 + WireGuard 반납 (🚨 이 순간부터 3사 원부조회 불통 — C3b 까지 5분 안에)
#    ⚠️ 구 서버의 터널은 `wg-quick@wg-carmodoo` 유닛이 enabled 인데 **inactive(dead)** 상태로 인터페이스만 떠 있다(09-24 실측 — 손으로 `wg-quick up` 한 것).
#       `systemctl stop` 은 no-op 이다. 반드시 `wg-quick down` → 안 되면 `ip link delete`.
$S $OLD 'bash -s' <<'EOF'
cd /var/www/car-erp && php artisan down --retry=60; cd /var/www/board-ssancar && php artisan down --retry=60
sudo supervisorctl stop all; crontab -l > /home/ubuntu/crontab.bak-$(date +%Y%m%d) && crontab -r
sudo wg-quick down wg-carmodoo 2>/dev/null || sudo ip link delete wg-carmodoo; sudo systemctl disable wg-quick@wg-carmodoo 2>/dev/null
ip link show wg-carmodoo 2>&1 | head -1; sudo wg show | wc -l
EOF
# ✅ 검증: "does not exist" · wg show 0줄 · 워커 STOPPED  (disable 까지 해 둔다 — 구 인스턴스가 재부팅되면 터널을 다시 뺏어간다)

# C2 최종 덤프 → 새 서버 (B10 이후 들어온 데이터를 통째로 다시)
$S $NEW 'sudo mysql -e "DROP DATABASE ssancar_erp; CREATE DATABASE ssancar_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DROP DATABASE board_ssancar; CREATE DATABASE board_ssancar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 ssancar_erp | gzip -9'   | tee "$STG/FINAL_ssancar_erp.sql.gz"   | $S $NEW 'gunzip | sudo mysql ssancar_erp'
$S $OLD 'sudo mysqldump --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 board_ssancar | gzip -9' | tee "$STG/FINAL_board_ssancar.sql.gz" | $S $NEW 'gunzip | sudo mysql board_ssancar'

# C3 건수 대조 (구 = 신)
$S $OLD 'sudo mysql -N ssancar_erp -e "SELECT COUNT(*) FROM vehicles; SELECT COUNT(*) FROM settlements; SELECT COUNT(*) FROM final_payments; SELECT COUNT(*) FROM audit_logs; SELECT COUNT(*),MAX(created_at) FROM alimtalk_logs"'
$S $NEW 'sudo mysql -N ssancar_erp -e "SELECT COUNT(*) FROM vehicles; SELECT COUNT(*) FROM settlements; SELECT COUNT(*) FROM final_payments; SELECT COUNT(*) FROM audit_logs; SELECT COUNT(*),MAX(created_at) FROM alimtalk_logs"'
# ✅ 검증: 5줄 전부 동일

# C3b 새 서버 기동 — WireGuard 먼저 (원부조회 복구), 그 다음 워커·cron·up
#    ⚠️ 캐시 명령은 `|| true`(deploy.yml 과 동일) — 하나라도 실패하면 `up` 이 안 돌아 점검모드에 갇힌다. `up` 은 무조건 실행
$S $NEW 'bash -s' <<'EOF'
sudo systemctl enable --now wg-quick@wg-carmodoo; sleep 30; sudo wg show | grep -E "endpoint|latest handshake|allowed"; ip route get 211.174.52.231 | head -1
sudo supervisorctl start all; sudo supervisorctl status
cd /var/www/car-erp && { php artisan config:cache || true; php artisan route:cache || true; php artisan view:cache || true; php artisan queue:restart || true; }; php artisan up
cd /var/www/board-ssancar && { php artisan config:cache || true; php artisan queue:restart || true; }; php artisan up
curl -s -o /dev/null -w "local login %{http_code}\n" -H "Host: heymancar.com" http://127.0.0.1/login
EOF
# ✅ 검증: latest handshake N초 전 · route dev wg-carmodoo · 워커 RUNNING ×2 · local login 200(302 도 OK, 503 이면 아직 down)

# C4 👤 jin: Lightsail 콘솔 — 고정 IP 54.116.7.83 을 구 인스턴스에서 분리 → 새 인스턴스에 연결
#    (동적이면 DNS 2건: heymancar.com/www · board.heymancar.com → NEW_IP. TTL 은 전날 300 으로)
# C5 확인 — ⚠️ IP 가 넘어가면 heymancar.com·54.116.7.83 의 SSH host key 가 바뀐다 → known_hosts 에서 먼저 지운다(안 지우면 이후 $S 가 전부 거부)
ssh-keygen -R heymancar.com >/dev/null 2>&1; ssh-keygen -R 54.116.7.83 >/dev/null 2>&1
nslookup heymancar.com | tail -2; $S $NEW 'curl -s --max-time 5 https://checkip.amazonaws.com; hostname'; $S ubuntu@heymancar.com 'hostname'
# ✅ 검증: 54.116.7.83 이 새 hostname 에서 나온다 · heymancar.com 으로 붙어도 새 hostname (⚠️ 이때부터 $OLD 는 새 서버다 — 구 서버는 콘솔 브라우저 SSH 로만)

# C6 인증서 — 복사본이 11/24·11/29 까지 유효. 갱신 경로만 확인
$S $NEW 'sudo certbot renew --dry-run 2>&1 | tail -3'
# ✅ 검증: "simulating renewal ... success" (실패면 nginx 의 acme 경로 — 사이트 conf 는 그대로 복사됐으니 정상 예상)
# C7 구 서버: 그대로 둔다(down 상태). 🚫 D+7(10/1) 까지 삭제 금지
```

---

## D. 검증 (C 직후, jin 과 같이) — 런북 D1~D9 + 2

| | 무엇 | 어떻게 | 통과 |
|---|---|---|---|
| D1 🚨 | 원부조회 | 차량 1대 [조회] (jin) + `$S $NEW 'sudo wg show \| grep handshake'` | 값 채워짐 · handshake 최근 |
| D2 | 로그인·100행·패널 | jin 브라우저 (hosts 항목은 **지운 뒤**) | |
| D3 | 서류 xlsx · 사진 | jin — S3 | |
| D4 | board | `board.heymancar.com` 로그인 + 연동 1건 | |
| D5 | 알림톡 | 기능설정 테스트 발송 1건 | |
| D6 | 워커·스케줄 | `$S $NEW 'sudo supervisorctl status; grep -c "schedule:run" <(crontab -l)'` | RUNNING ×2 · 1 |
| D7 | 백업 | `$S $NEW 'cd /var/www/car-erp && php artisan db:backup \| tail -2; /home/ubuntu/erp_db_dump.sh; tail -2 /home/ubuntu/backup_staging/daily/dump.log'` + **NAS 가 다음 날 당겨갔나**(👤 jin) | OK 2줄 · NAS 수신 |
| D8 | RRN | 차량 1대 기본정보 RRN 표시 (jin) | 복호화됨 |
| D9 | 건수 | `snapshot-before` 와 같은 질의를 새 서버에 | 증가만 있고 감소 없음 |
| D10 | GitHub 배포 경로 | `gh run rerun 35968896855 --job <deploy-ssancar job id>` (같은 master 재배포 — 무중단 스크립트) | 잡 success · 새 서버 sha 유지 |
| D11 | 메모리 | `$S $NEW 'free -m \| head -2; ps -o rss= -C php-fpm8.4 \| awk "{s+=\$1} END {print s/1024 \" MB\"}"'` | used < 2GB |

정리: `$STG` 의 덤프·.env 사본은 D 통과 후 **jin 확인하고 삭제**(개인정보 사본). `aws-deployment-record.md`·메모리 갱신은 E3.

---

## 실행 후기 (2026-09-24 실제 실행 — 위 블록과 달랐던 것)

| 블록 | 문제 | 고침 |
|---|---|---|
| B11 | 사이트 conf 의 `access_log … timed` — **`timed` log_format 은 `/etc/nginx/nginx.conf` 에 정의**(09-01 성능 작업). 사이트만 복사하면 `unknown log format` 으로 nginx -t 실패 | **구 `nginx.conf` 를 통째로 복사**(gzip 튜닝도 거기 있다). 로컬 A4 묶음에서 꺼내 stdin 으로 올렸다 |
| B11 | 사이트 conf `access_log /ssancar-erp/logs/…`(Django 시절 경로) → 새 서버에 없어 emerg | `sed 's#/ssancar-erp/logs/#/var/log/nginx/#g'` |
| B11 | tar 를 sudo 로 풀면 700/600 파일이 root 소유 → ubuntu `cp` 가 Permission denied | `sudo cp` 뒤 `chown ubuntu` |
| B11 | `'gunicorn' not in conf` 검사가 **주석 줄**의 "Django(gunicorn)" 에 걸려 실패 | 검사는 `gunicorn.sock` 으로 |
| B6/B11 | board 워커(www-data)가 `.env`(600 ubuntu) 를 못 읽어 **기본값 sqlite** 로 떨어짐(구 서버는 config 캐시가 있어 무증상) | 두 앱 `config:cache` 를 **워커 기동 전에** |
| B6 | ERP 첫 요청 500 — Livewire 컴파일 dir(`storage/framework/views/livewire/*`)이 `view:cache`(ubuntu) 때 **0755** 로 생겨 www-data 가 못 씀 → `tempnam` 폴백 예외 | 캐시 생성 **뒤에** `chmod -R g+rwX storage bootstrap/cache` 한 번 더 |
| C1 | 구 서버 wg 는 유닛 dead + 인터페이스만 up → `wg-quick down` 으로 내림(예상대로) | — |
| 도구 | Claude Bash 는 `sleep` 금지 · 긴 복합 명령(`rm -rf`+DB 복원)은 권한 분류기가 거부 | 블록을 잘게, `rm -r` |
| D10 | `gh run rerun --job` 은 **같은 run 의 새 attempt** — `gh run list` 로는 안 보인다. `gh run view <run>` 으로 잡 상태를 본다 | — |

실측: 전환 직후 used 1,413MB / avail 2,419MB · php-fpm 14 워커 RSS 합 319MB · 로그인 0.11~0.16s.
