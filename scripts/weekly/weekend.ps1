# 주말 자동 루틴 — jin PC 작업 스케줄러 「car-erp weekend routines」 토 10:00 · 일 10:00(재시도) (jin 2026-11-16)
#   ① 메모리·문서 측정(읽기만) → 관제 ops:mark memory-doc-cleanup (후보 있으면 todo)
#   ② 씬 점검(로컬 8001, Playwright) → 관제 ops:mark scene-check ok/failed · 실패면 텔레그램(heymanerp notify:send)
#   이번 회차(가장 최근 토요일)에 이미 끝났으면 일요일 재시도는 건너뛴다.
#   🚫 운영 데이터에 쓰지 않는다 — 씬 점검은 로컬 DB 만 바꾼다(되돌리기 시더). 관제 기록만 허브로 서명 POST.
#   ⚠️ 저장은 UTF-8 BOM + CRLF (SKILLS §8 #112-B).
param([switch]$Force, [switch]$SkipScene, [switch]$SkipMemory)
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
    return @{ code = $p.ExitCode; out = [string]$out; err = [string]$err }
}
function Mark($k, $status, $note) {
    $safe = ($note -replace '"', "'")
    $r = Run $php @('artisan', 'ops:mark', $k, "--status=$status", "--note=`"$safe`"", "--push=$hub") 60
    Log "ops:mark $k $status ($safe) -> exit $($r.code) $($r.out.Trim()) $($r.err.Trim())"
}

if ((Test-Path $done) -and -not $Force) { Log 'already done this cycle — skip'; exit 0 }
Log "start cycle $stamp"

# ① 메모리·문서 측정(읽기만)
if (-not $SkipMemory) {
    $m = Run $php @('scripts/weekly/memory-measure.php') 120
    $last = ($m.out -split "`r?`n" | Where-Object { $_.Trim().StartsWith('{') } | Select-Object -Last 1)
    try { $j = $last | ConvertFrom-Json; Mark 'memory-doc-cleanup' $j.status $j.note } catch { Mark 'memory-doc-cleanup' 'failed' ('측정 실패: ' + $m.err.Trim()) }
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
"done $(Get-Date -Format s)" | Out-File $done -Encoding utf8
Log 'done'
