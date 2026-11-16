# 주말 자동 루틴 — jin PC 작업 스케줄러 「car-erp weekend routines」 토 10:00 · 일 10:00(재시도) (jin 2026-11-16)
#   ① 메모리·문서 측정(읽기만) → 관제 ops:mark memory-doc-cleanup (후보 있으면 todo)
#   ② 씬 점검(로컬 8001, Playwright) → 관제 ops:mark scene-check ok/failed · 실패면 텔레그램(heymanerp notify:send)
#   이번 회차(가장 최근 토요일)에 이미 끝났으면 일요일 재시도는 건너뛴다.
#   🚫 운영 데이터에 쓰지 않는다 — 씬 점검은 로컬 DB 만 바꾼다(되돌리기 시더). 관제 기록만 허브로 서명 POST.
#   ⚠️ 저장은 UTF-8 BOM + CRLF (SKILLS §8 #112-B).
param([switch]$Force, [switch]$SkipScene, [switch]$SkipMemory, [switch]$SkipBoard)
$ErrorActionPreference = 'Continue'
$repo = 'C:\xampp\htdocs\car-erp'
$php = 'C:\xampp\php\php.exe'
$node = 'C:\Program Files\nodejs\node.exe'
$hub = 'https://heymancar.com'
$key = "$env:USERPROFILE\.ssh\car_erp_key"
$logDir = "$repo\storage\logs\weekly"
New-Item -ItemType Directory -Force $logDir | Out-Null
$cycle = (Get-Date).Date
while ($cycle.DayOfWeek -ne 'Saturday') { $cycle = $cycle.AddDays(-1) }
$stamp = $cycle.ToString('yyyyMMdd')
$done = "$logDir\done-$stamp.txt"
$log = "$logDir\weekend-$stamp.log"
function Log($m) { "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $m" | Out-File $log -Append -Encoding utf8 }

function Run($exe, [string[]]$argList, $timeoutSec = 1800) {
    $o = "$logDir\out-$([guid]::NewGuid().ToString('N')).txt"; $e = "$o.err"
    $p = Start-Process -FilePath $exe -ArgumentList $argList -WorkingDirectory $repo -NoNewWindow -PassThru -RedirectStandardOutput $o -RedirectStandardError $e
    $null = $p.Handle
    if (-not $p.WaitForExit($timeoutSec * 1000)) { try { $p.Kill() } catch {}; Log "TIMEOUT $exe" }
    $out = if (Test-Path $o) { Get-Content $o -Raw -Encoding utf8 } else { '' }
    $err = if (Test-Path $e) { Get-Content $e -Raw -Encoding utf8 } else { '' }
    Remove-Item $o, $e -ErrorAction SilentlyContinue
    # 빈 출력은 null 로 온다(Get-Content -Raw 빈 파일) — 문자열로 고정해야 .Trim() 이 안 죽는다(11-16 첫 실행에서 실제로 죽었다)
    return @{ code = $p.ExitCode; out = $(if ($out) { [string]$out } else { '' }); err = $(if ($err) { [string]$err } else { '' }) }
}
function Mark($k, $status, $note) {
    $safe = ($note -replace '"', "'")
    $r = Run $php @('artisan', 'ops:mark', $k, "--status=$status", "--note=`"$safe`"", "--push=$hub") 60
    Log "ops:mark $k $status ($safe) -> exit $($r.code) $("$($r.out)".Trim()) $("$($r.err)".Trim())"
}

if ((Test-Path $done) -and -not $Force) { Log 'already done this cycle — skip'; exit 0 }
Log "start cycle $stamp"

# ① 메모리·문서 측정(읽기만)
if (-not $SkipMemory) {
    $m = Run $php @('scripts/weekly/memory-measure.php') 120
    $last = ($m.out -split "`r?`n" | Where-Object { $_.Trim().StartsWith('{') } | Select-Object -Last 1)
    try { $j = $last | ConvertFrom-Json; Mark 'memory-doc-cleanup' $j.status $j.note } catch { Mark 'memory-doc-cleanup' 'failed' ('측정 실패: ' + "$($m.err)".Trim()) }
}

# ② 씬 점검 (러너가 아직 없으면 건너뛴다 — 만드는 동안 「실패」 텔레그램이 가지 않게)
if (-not (Test-Path "$repo\scripts\scene\check\run.js")) { Log 'scene runner not built yet - skip'; $SkipScene = $true }
if (-not $SkipScene) {
    $sceneOk = $true
    $note = ''
    $out = ''
    $alive = $false
    try { $alive = ((Invoke-WebRequest -Uri 'http://localhost:8001/login' -UseBasicParsing -TimeoutSec 20).StatusCode -eq 200) } catch {}
    if (-not $alive) {
        $sceneOk = $false
        $note = '로컬 서버 8001 꺼짐 — 안 돌았음'
    } else {
        $r = Run $node @('scripts/scene/check/run.js') 3600
        $out = $r.out
        $out | Out-File "$logDir\scene-$stamp.txt" -Encoding utf8
        $sum = ($out -split "`r?`n" | Where-Object { $_ -match '^SUMMARY ' } | Select-Object -Last 1)
        $note = if ($sum) { $sum.Substring(8).Trim() } else { "러너 exit $($r.code)" }
        $sceneOk = ($r.code -eq 0)
    }
    Mark 'scene-check' $(if ($sceneOk) { 'ok' } else { 'failed' }) $note
    if (-not $sceneOk) {
        $body = "$logDir\tg-$stamp.txt"
        $fails = ($out -split "`r?`n" | Where-Object { $_ -match '^FAIL ' } | Select-Object -First 10) -join "`n"
        "씬 점검이 실패했습니다 ($stamp).`n$note`n$fails`n`n대화창에 「씬 점검 실패 봐줘」 — 결과 = $logDir\scene-$stamp.txt" | Out-File $body -Encoding utf8
        $p = Start-Process -FilePath 'ssh' -ArgumentList @('-i', $key, '-o', 'BatchMode=yes', 'ubuntu@52.79.200.151', "cd /var/www/car-erp && php artisan notify:send --title='씬 점검 실패' --body=-") -NoNewWindow -PassThru -RedirectStandardInput $body -RedirectStandardOutput "$body.out" -RedirectStandardError "$body.err"
        $null = $p.Handle
        [void]$p.WaitForExit(120000)
        Log "telegram sent (exit $($p.ExitCode))"
    }
}
# ③ board 씬 점검 — ERP 점검이 끝난 뒤 이어서(같은 로컬 데이터를 동시에 안 건드리게, jin 결정 11-16).
#    board 러너가 자기 키(board-scene-check)로 관제에 직접 기록한다(--mark). --after-erp = ERP 시더가 막 돌았다는 표시 — board 는 이게 있을 때만 ERP 에 쓰는 씬(2b)을 돈다. exit 0 통과 / 1 실패 / 2 시작 못 함(board 8003 꺼짐 등).
$boardDir = 'C:\xampp\htdocs\board\scripts\guide-shots'
if (-not $SkipBoard -and (Test-Path "$boardDir\check\run.mjs")) {
    $o = "$logDir\board-$stamp.txt"
    $bp = Start-Process -FilePath $node -ArgumentList @('check/run.mjs', '--mark', '--after-erp') -WorkingDirectory $boardDir -NoNewWindow -PassThru -RedirectStandardOutput $o -RedirectStandardError "$o.err"
    $null = $bp.Handle
    if (-not $bp.WaitForExit(3600 * 1000)) { try { $bp.Kill() } catch {}; Log 'board runner TIMEOUT' }
    Log "board runner exit $($bp.ExitCode) (결과 = $o)"
} else {
    Log 'board runner skip (없음 또는 -SkipBoard)'
}

"done $(Get-Date -Format s)" | Out-File $done -Encoding utf8
Log 'done'
