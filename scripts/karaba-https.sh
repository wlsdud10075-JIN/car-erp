#!/usr/bin/env bash
# karaba HTTPS — certbot + nginx 도메인 + APP_URL https
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

echo "===== [1/5] certbot 설치 ====="
sudo apt-get update -y >/dev/null
sudo apt-get install -y certbot python3-certbot-nginx >/dev/null
echo "certbot: $(certbot --version 2>&1)"

echo "===== [2/5] nginx server_name 에 도메인 추가 ====="
sudo sed -i 's/^    server_name .*/    server_name karaba-erp.com www.karaba-erp.com 15.164.91.242;/' /etc/nginx/sites-available/car-erp
sudo nginx -t
sudo systemctl reload nginx

echo "===== [3/5] certbot 발급 (HTTP→HTTPS 리다이렉트 포함) ====="
sudo certbot --nginx -d karaba-erp.com -d www.karaba-erp.com \
  --non-interactive --agree-tos -m wlsdud10075@gmail.com --redirect

echo "===== [4/5] APP_URL https 전환 ====="
cd /var/www/car-erp
sed -i 's|^APP_URL=.*|APP_URL=https://karaba-erp.com|' .env
php artisan config:cache >/dev/null
sudo systemctl reload php8.4-fpm

echo "===== [5/5] 검증 ====="
echo "APP_URL: $(grep '^APP_URL=' .env)"
echo "-- https://karaba-erp.com/login --"
curl -sI https://karaba-erp.com/login | head -4
echo "-- http→https 리다이렉트 --"
curl -sI http://karaba-erp.com/ | grep -iE 'HTTP|location'
echo "-- 인증서 만료 --"
echo | openssl s_client -servername karaba-erp.com -connect karaba-erp.com:443 2>/dev/null | openssl x509 -noout -dates 2>/dev/null
echo "===== 완료 ====="
