# 야간 조사 (회사 GPU PC, 매일 03:30) — 야간 배치 실행기 2단계 (jin 2026-09-28 승인)
#   1) 최신 코드·메모리 pull (읽기 전용 사본)
#   2) 3사 서버에서 읽기 전용 SSH 로 재료 수집 (아침 점검 dry-run · 오늘·어제 ERROR 로그 · board 감사 JSON)
#   3) claude -p 로 원인·제안 초안 (도구 = Read/Grep/Glob 만 — 쓰기 없음)
#   4) 결과 = reports\report-YYYYMMDD.md  (텔레그램 전송은 2~3일 관찰 뒤 켠다: -SendTelegram)
# 🚫 이 스크립트는 아무것도 수정하지 않는다. 서버 SSH 는 읽기 명령만.
# ⚠️ 작업 스케줄러(세션 0) 안의 PowerShell 에서 `& ssh …` 로 출력을 잡으면 영영 멈춘다(09-28 실측 3회).
#    콘솔 앱은 전부 Start-Process + 파일 리다이렉트로 부른다. 바꾸지 말 것.
param([switch]$SendTelegram, [switch]$SkipPull)

$ErrorActionPreference = 'Continue'
$base   = "$env:USERPROFILE\nightly"
$repo   = 'C:\xampp\htdocs\car-erp'
$board  = 'C:\xampp\htdocs\board'
$memDir = "$env:USERPROFILE\.claude\projects"
$key    = "$env:USERPROFILE\.ssh\car_erp_key"
$today  = Get-Date -Format 'yyyyMMdd'
$stamp  = Get-Date -Format 'yyyy-MM-dd HH:mm'
New-Item -ItemType Directory -Force "$base\reports", "$base\inputs", "$base\logs", "$base\tmp" | Out-Null
$log    = "$base\logs\run-$today.log"
$input_ = "$base\inputs\input-$today.txt"
$report = "$base\reports\report-$today.md"
"[$stamp] start" | Out-File $log -Encoding utf8

function Log($m) { "[$(Get-Date -Format HH:mm:ss)] $m" | Out-File $log -Append -Encoding utf8 }

# 콘솔 앱 실행 — 출력은 파일로만 받는다(위 ⚠️). 반환 = stdout 문자열.
function RunCapture($exe, [string[]]$argList, $stdinFile = $null, $timeoutSec = 600) {
    $o = "$base\tmp\out-$([guid]::NewGuid().ToString('N')).txt"
    $e = "$o.err"
    $params = @{ FilePath = $exe; ArgumentList = $argList; NoNewWindow = $true; PassThru = $true; RedirectStandardOutput = $o; RedirectStandardError = $e }
    if ($stdinFile) { $params.RedirectStandardInput = $stdinFile }
    $p = Start-Process @params
    if (-not $p.WaitForExit($timeoutSec * 1000)) { try { $p.Kill() } catch {}; Log "TIMEOUT ${timeoutSec}s: $exe $($argList[0..2] -join ' ')" }
    $txt = if (Test-Path $o) { Get-Content $o -Raw -Encoding utf8 } else { '' }
    $err = if (Test-Path $e) { (Get-Content $e -Encoding utf8 | Where-Object { $_ -notmatch 'post-quantum|store now|openssh.com/pq|^\s*$' }) -join "`n" } else { '' }
    Remove-Item $o, $e -ErrorAction SilentlyContinue
    if ($err) { Log "stderr($exe): $($err.Substring(0, [Math]::Min(200, $err.Length)))" }
    return [string]$txt
}

function SshRead($host_, $cmd, $timeoutSec = 120) {
    Log "ssh $host_ :: $($cmd.Substring(0, [Math]::Min(60, $cmd.Length)))"
    return RunCapture 'ssh' @('-n', '-i', $key, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=10', '-o', 'ServerAliveCountMax=3', '-o', 'StrictHostKeyChecking=accept-new', "ubuntu@$host_", $cmd) $null $timeoutSec
}

# 1) pull ------------------------------------------------------------------
if (-not $SkipPull) {
    foreach ($r in @($repo, $board)) { [void](RunCapture 'git' @('-C', $r, 'pull', '-q', '--ff-only') $null 120) }
    [void](RunCapture 'git' @('-C', $memDir, 'fetch', '-q', 'origin', 'main') $null 120)
    [void](RunCapture 'git' @('-C', $memDir, 'checkout', '-q', 'origin/main', '--', 'C--xampp-htdocs-car-erp', 'C--xampp-htdocs-board', 'C--xampp-htdocs-ssancar-htdocs') $null 60)
}
Log ("car-erp=" + (RunCapture 'git' @('-C', $repo, 'rev-parse', '--short', 'HEAD')).Trim() + " board=" + (RunCapture 'git' @('-C', $board, 'rev-parse', '--short', 'HEAD')).Trim())

# 2) 재료 수집 ----------------------------------------------------------------
$servers = @(
    @{ name = 'heymanerp';  host = '52.79.200.151' },
    @{ name = 'ssancarerp'; host = 'heymancar.com' },
    @{ name = 'karabaerp';  host = 'karaba-erp.com' }
)
$d1 = (Get-Date).ToString('yyyy-MM-dd'); $d0 = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$sb = New-Object System.Text.StringBuilder
[void]$sb.AppendLine("# 야간 조사 재료 — $stamp (읽기 전용 수집)")
foreach ($s in $servers) {
    [void]$sb.AppendLine("`n## [$($s.name)] 아침 점검 dry-run")
    [void]$sb.AppendLine((SshRead $s.host 'cd /var/www/car-erp && php artisan system:health-check --dry 2>&1 | tail -30'))
    [void]$sb.AppendLine("`n## [$($s.name)] 오늘·어제 ERROR 로그 (최근 40줄, 각 300자)")
    [void]$sb.AppendLine((SshRead $s.host "cd /var/www/car-erp && grep -E '^\[($d0|$d1)' storage/logs/laravel.log 2>/dev/null | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY)' | tail -40 | cut -c1-300"))
}
[void]$sb.AppendLine("`n## [heymanboard] board→ERP 감사 JSON")
[void]$sb.AppendLine((SshRead '52.79.200.151' 'cat /var/www/board/storage/app/integration/purchase-sync-audit.json 2>/dev/null'))
[void]$sb.AppendLine("`n## [ssancarboard] board→ERP 감사 JSON")
[void]$sb.AppendLine((SshRead 'heymancar.com' 'cat /var/www/board-ssancar/storage/app/integration/purchase-sync-audit.json 2>/dev/null'))
$sb.ToString() | Out-File $input_ -Encoding utf8
Log "inputs collected ($((Get-Item $input_).Length) bytes)"

# 3) claude -p (프롬프트는 STDIN 으로 — 긴 본문·따옴표를 인자로 넘기지 않는다) ---------
$promptFile = "$base\tmp\prompt-$today.txt"
(Get-Content "$base\prompt.md" -Raw -Encoding utf8).Replace('{{INPUT_PATH}}', $input_).Replace('{{DATE}}', $stamp) | Out-File $promptFile -Encoding utf8
Push-Location $repo
try {
    $claudeOut = RunCapture 'claude' @('-p', '표준입력으로 온 지시문을 그대로 따르라.', '--allowedTools', 'Read,Grep,Glob', '--output-format', 'text') $promptFile 900
} finally { Pop-Location }
$header = "# 야간 조사 보고 — $stamp`n(회사 PC 자동 생성 · 읽기 전용 · 수정 0)`n`n"
($header + $claudeOut) | Out-File $report -Encoding utf8
Log "report written ($((Get-Item $report).Length) bytes)"

# 4) 텔레그램 (관찰 기간엔 끔) — heymanerp 의 notify:send 경유, 토큰은 서버에만 --------
if ($SendTelegram) {
    $bodyFile = "$base\tmp\body-$today.txt"
    Get-Content $report -Raw -Encoding utf8 | Out-File $bodyFile -Encoding utf8
    [void](RunCapture 'ssh' @('-i', $key, '-o', 'BatchMode=yes', 'ubuntu@52.79.200.151', "cd /var/www/car-erp && php artisan notify:send --title='야간 조사' --body=-") $bodyFile 120)
    Log "telegram sent via heymanerp notify:send"
}
Log "done"
