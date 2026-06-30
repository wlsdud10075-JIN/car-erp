#!/usr/bin/env bash
# karaba GitHub Actions 전용 배포키 생성 + authorized_keys 등록
set -euo pipefail
if [ ! -f ~/.ssh/karaba_deploy ]; then
  ssh-keygen -t ed25519 -f ~/.ssh/karaba_deploy -N "" -C "github-deploy-karaba" -q
fi
grep -qF "$(cat ~/.ssh/karaba_deploy.pub)" ~/.ssh/authorized_keys || cat ~/.ssh/karaba_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
echo "keygen+authorized_keys done"
echo "pub: $(cat ~/.ssh/karaba_deploy.pub)"
