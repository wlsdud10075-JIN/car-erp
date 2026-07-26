# car-erp 운영 인프라 — 3박스 용량·NICE 게이트웨이·swap (2026-07-26)

> board 세션이 `board/meetings/handoff-car-erp-infra-fpm-2026-07-26.md` 로 전달한 인프라 사실 +
> car-erp 세션이 추가한 변경(NICE 동시 상한·swap)을 **car-erp repo 에 committed 로 미러링**.
> 메모리는 세션·레포 간 전파 안 되므로, 인프라 사실은 이 파일이 단일 출처(committed → 전 PC·세션 전파).

## 박스 매핑 (2026-07-26 실측)

| 박스 | 올라간 앱 | RAM | swap |
|---|---|---|---|
| `54.116.7.83` = heymancar.com | **ssancarerp** + ssancarboard + Django(잔존) + **NICE 게이트웨이** | 7.8GB | **2GB (07-26 추가)** |
| `52.79.200.151` = heysellcar.com | **heymanerp** + heymanboard | 3.8GB | **2GB (07-26 추가)** |
| `15.164.91.242` = karaba-erp.com | **karabaerp** | 1.9GB | 기존 보유 |

⚠️ **박스당 php-fpm 풀 1개(www.conf)** — 같은 박스의 ERP·board 가 **워커를 공유**한다. 한쪽이 워커를 소진하면 다른 쪽도 멈춘다.

## 2026-07-26 변경 내역

### A. PHP-FPM 워커 증설 (board 세션 적용)
- `54.116.7.83`: max_children 5→**14** / `52.79.200.151`: 5→**10** / karaba: **5 유지**(RAM 1.9GB 부족 → 워커 튜닝 아니라 인스턴스 업그레이드가 답).
- 백업 `www.conf.bak-20260726`. 롤백 = 백업 복구 + `sudo systemctl reload php8.4-fpm`.

### B. nginx 타임아웃 (board 세션 적용)
- 3박스 `/etc/nginx/nginx.conf` http{} 에 `fastcgi_read_timeout 90s` 추가(그 전 기본 60s). NICE 조회(`Http::timeout(55)`)가 60s 에 잘려 502+과금 나가던 것 해소. 백업 `nginx.conf.bak-20260726`.

### C. swap 2GB (car-erp 세션, 이 세션 적용)
- `52.79.200.151`·`54.116.7.83` 에 `/swapfile` 2G + `vm.swappiness=10` + `/etc/fstab` 영구화. karaba 는 기존 보유 → **3박스 전부 swap 확보**.
- 목적 = no-swap 이던 2박스에 **OOM 쿠션**(워커 10~14 + 대용량 export·조회 동시 스파이크 대비). 평소 여유는 충분(heyman available 2.5GB), swappiness 10 = RAM 우선.
- 롤백 = `sudo swapoff /swapfile && sudo rm /swapfile` + fstab/sysctl.conf 줄 제거.

### D. heyman 배포 SSH 타임아웃 fix (car-erp, master `3214c59`)
- `.github/workflows/deploy.yml`: 3사 잡 `timeout: 120s`(연결, 기본 30s)+`command_timeout: 20m`. heyman `deploy` 잡은 `continue-on-error` 1차 + 조건부 자동 재시도 1회.
- 배경 = heyman(가장 바쁜 원본 인스턴스)만 간헐적으로 SSH TCP dial 이 30s 초과("dial tcp i/o timeout") → 배포 실패(스크립트 미실행이라 사이트는 UP 유지). 상세 = 메모리 `project_heyman_deploy_ssh_timeout`.

### E. NICE 게이트웨이 전역 동시 조회 상한 (car-erp, master `ce48448`)
- **모든 NICE 차량정보 조회**가 `ProvideNiceLookupController`(54.116.7.83, `POST /provide/api/nice-lookup`) 한 곳을 지난다(3사 ERP `NICE_PROVIDE_URL` 이 전부 `https://heymancar.com/provide/...`). 조회 1건이 워커를 **55~90초** 점유.
- **슬롯 락 세마포어**(cache_locks, database 드라이버 = 워커 간 원자적) — 기본 **동시 4건**(`NICE_MAX_CONCURRENT`, config `services.nice.max_concurrent`). 다 차면 90초 붙잡지 않고 **즉시 HTTP 429** 로 워커 반납. 락 TTL 120s(크래시 워커 슬롯 자동 해제 = 락 누수 방지).
- ⚠️ **board 영향**: board 가 NICE 를 ERP 경유로 조회하면 이 상한 적용 → **동시 4 초과 시 429**("동시 조회가 많아 잠시 후"). board 는 429 를 에러가 아니라 **재시도 신호**로 처리해야.

## NICE 게이트웨이 경로 (참고)
- 3사 ERP `NICE_PROVIDE_URL` = `https://heymancar.com/provide/api/nice-lookup/` → `54.116.7.83` → `ProvideNiceLookupController` → `NiceDirectClient`(NICE IP 화이트리스트가 이 박스라 직접 2단계 호출). 다른 박스는 IP 불일치로 이 경로 경유. Django(gunicorn)는 `/provide/` 나머지 prefix 만, **2026-06-27 이후 트래픽 0**.

## carmodoo 원부조회 (NICE 와 별개 서비스)
- `CarmodooService`(sh.carmodoo.com, 압류/저당/구조). timeout **15s**(NICE 보다 가벼움), 사무실 **WireGuard 터널(한 IP)** 경유, **3사 단일계정 공유**. 상세 = 메모리 `project_wonbu_lookup`.
- **board 확장(board 사용자 원부조회) = 🅿️ 킵**(jin 2026-07-26). ⛔ 개인계정들을 우리 고정 IP 로 프록시 금지("여러 계정 한 IP"). 상세·선택지 A/B = 메모리.

## 아직 안 한 것 (board 세션 §4 지적, ERP 판단)
- **carmodoo 동시 조회 상한** — board 확장 시 필요(단일계정 보호). NICE 캡과 같은 패턴.
- **Django 철거** — 트래픽 0 이라 지금이 안전. 철거 시 nginx `/provide/` prefix 블록도 정리.
