#!/usr/bin/env bash
# karaba 서버 3단계 — nginx + cron + S3검증 + 동작확인
set -euo pipefail
APP_DIR=/var/www/car-erp

echo "===== [1/6] nginx 사이트 설정 ====="
sudo tee /etc/nginx/sites-available/car-erp > /dev/null <<'NGINXEOF'
server {
    listen 80;
    listen [::]:80;
    server_name 15.164.91.242 _;
    root /var/www/car-erp/public;
    index index.php;
    client_max_body_size 50M;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINXEOF
sudo ln -sf /etc/nginx/sites-available/car-erp /etc/nginx/sites-enabled/car-erp
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx

echo "===== [2/6] cron (schedule:run) ====="
LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
( crontab -l 2>/dev/null | grep -v 'artisan schedule:run' || true ; echo "$LINE" ) | crontab -
crontab -l | tail -2

echo "===== [3/6] S3 연결 검증 ====="
cd "$APP_DIR"
php artisan tinker --execute='try { \Illuminate\Support\Facades\Storage::disk("s3")->put("healthcheck.txt","ok-".now()); echo \Illuminate\Support\Facades\Storage::disk("s3")->exists("healthcheck.txt") ? "S3_OK" : "S3_FAIL"; \Illuminate\Support\Facades\Storage::disk("s3")->delete("healthcheck.txt"); } catch (\Throwable $e) { echo "S3_ERROR: ".$e->getMessage(); }'
echo ""

echo "===== [4/6] DB 백업 1회 검증 (로컬+S3) ====="
php artisan db:backup 2>&1 | tail -3 || echo "db:backup 실패(확인필요)"

echo "===== [5/6] 로컬 HTTP 응답 ====="
curl -sI http://127.0.0.1/ | head -5

echo "===== [6/6] 로그인 페이지 확인 ====="
code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/login)
echo "GET /login → HTTP $code"

echo "===== 3단계 완료 ====="
