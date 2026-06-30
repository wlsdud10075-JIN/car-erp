#!/usr/bin/env bash
# karaba 4단계 — master(f59b27e/9b7d001) 반영 + 서류 테넌트 분기 활성화
set -euo pipefail
cd /var/www/car-erp

echo "===== git pull ====="
git pull origin master
echo "HEAD: $(git log --oneline -1)"

echo "===== 캐시 재빌드 + php-fpm 리로드 ====="
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.4-fpm

echo "===== 검증 ====="
echo "karaba 템플릿 수: $(ls resources/templates/karaba/*.xlsx 2>/dev/null | wc -l)"
echo "DocumentFiller seam: $(grep -c "company.template_set" app/Services/Documents/DocumentFiller.php)"
php artisan tinker --execute='echo "template_set=".config("company.template_set");' 2>/dev/null | tail -1
echo ""
echo "===== 4단계 완료 ====="
