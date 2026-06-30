#!/usr/bin/env bash
# karaba NICE 연동 — heyman 동일(heymancar.com 미들웨어 경유)
set -euo pipefail
cd /var/www/car-erp
sed -i 's|^NICE_PROVIDE_URL=.*|NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/|' .env
sed -i 's|^NICE_PROVIDE_TOKEN=.*|NICE_PROVIDE_TOKEN=ssancar-pr0vide-api-token-20260310|' .env
php artisan config:cache >/dev/null
sudo systemctl reload php8.4-fpm
echo "적용:"
grep -E '^NICE_' .env
