#!/usr/bin/env bash
# karaba 서버 1단계 — 시스템 스택 (PHP 8.4 + nginx + MySQL + Node + Composer)
# 실행: ssh ubuntu@karaba "bash /tmp/karaba-1-stack.sh"
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

echo "===== [1/7] swap 2G (2GB RAM OOM 방어) ====="
if [ ! -f /swapfile ]; then
  sudo fallocate -l 2G /swapfile
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile
  sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
fi
free -m | awk '/Swap:/{print "swap="$2"MB"}'

echo "===== [2/7] apt update + ondrej/php PPA + NodeSource 22 ====="
sudo apt-get update -y
sudo apt-get install -y software-properties-common curl ca-certificates gnupg unzip git
sudo add-apt-repository -y ppa:ondrej/php
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get update -y

echo "===== [3/7] PHP 8.4 + 확장 ====="
sudo apt-get install -y \
  php8.4-cli php8.4-fpm php8.4-mysql php8.4-gd php8.4-zip \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-intl \
  php8.4-dom php8.4-simplexml
sudo update-alternatives --set php /usr/bin/php8.4 || true

echo "===== [4/7] nginx + MySQL + Node ====="
sudo apt-get install -y nginx mysql-server nodejs

echo "===== [5/7] Composer (공식 설치기 → /usr/local/bin/composer) ====="
if ! command -v composer >/dev/null 2>&1; then
  php -r "copy('https://getcomposer.org/installer','/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

echo "===== [6/7] 서비스 기동 확인 ====="
sudo systemctl enable --now php8.4-fpm nginx mysql >/dev/null 2>&1 || true
sudo systemctl is-active php8.4-fpm nginx mysql | tr '\n' ' '; echo

echo "===== [7/7] 버전 확인 ====="
php -v | head -1
echo -n "composer: "; composer --version 2>/dev/null | head -1
echo -n "node: "; node -v
echo -n "npm: "; npm -v
echo -n "nginx: "; nginx -v 2>&1
mysql --version
echo "zip ext: $(php -m | grep -c zip)  gd ext: $(php -m | grep -c gd)  dom ext: $(php -m | grep -c dom)  bcmath: $(php -m | grep -c bcmath)  intl: $(php -m | grep -c intl)"
echo "===== 1단계 완료 ====="
