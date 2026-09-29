# Notion 발행 순찰 (회사 GPU PC, 매일 04:30) — 야간 배치 실행기 4단계 (#6, jin 2026-09-29 착수)
#   1) 최신 코드 pull (car-erp · board dev)
#   2) Notion --verify 4종 (car-erp 카드·부서 가이드·워크플로우 / board 카드) — 전부 읽기 전용, LLM 없이 PHP 만
#   3) -Apply 가 켜져 있고 불일치가 있을 때만 Codex 를 부른다 (AGENTS.md → CODEX_NOTION_HANDOFF.md §9-A/9-B 대로 타깃 발행 → 재검증)
#   4) 결과 = reports\notion-YYYYMMDD.md  (텔레그램은 관찰 뒤 -SendTelegram)
# 🚫 기본값(-Apply 없음)은 Notion 에 아무것도 쓰지 않는다. --verify 는 불일치가 있으면 exit 1 이 정상이다(실패가 아니다).
# ⚠️ 세션 0 PowerShell 에서 콘솔 앱 출력을 `& exe` 로 잡으면 멈춘다(SKILLS §8 #112) — 전부 Start-Process + 파일 리다이렉트.
# ⚠️ 이 파일은 UTF-8 **BOM** 으로 저장해야 한다 — BOM 이 없으면 PowerShell 5.1 이 CP949 로 읽어 한글로 끝나는 줄의 개행을 먹고 다음 줄이 주석에 붙는다(09-29 실측).
param([switch]$Apply, [switch]$SendTelegram, [switch]$SkipPull)

$ErrorActionPreference = 'Continue'
$base    = "$env:USERPROFILE\nightly"
$htdocs  = 'C:\xampp\htdocs'
$repo    = "$htdocs\car-erp"
$board   = "$htdocs\board"
$php     = "$env:USERPROFILE\php\php.exe"
$key     = "$env:USERPROFILE\.ssh\car_erp_key"
$today   = Get-Date -Format 'yyyyMMdd'
$stamp   = Get-Date -Format 'yyyy-MM-dd HH:mm'
New-Item -ItemType Directory -Force "$base\reports", "$base\logs", "$base\tmp" | Out-Null
$log     = "$base\logs\notion-$today.log"
$report  = "$base\reports\notion-$today.md"
$verifyF = "$base\tmp\notion-verify-$today.txt"
"[$stamp] start apply=$Apply" | Out-File $log -Encoding utf8

function Log($m) { "[$(Get-Date -Format HH:mm:ss)] $m" | Out-File $log -Append -Encoding utf8 }

# 콘솔 앱 실행 — 출력은 파일로만. 반환 = @{ out=stdout; code=exit }
function RunCapture($exe, [string[]]$argList, $stdinFile = $null, $timeoutSec = 600) {
    $o = "$base\tmp\out-$([guid]::NewGuid().ToString('N')).txt"
    $e = "$o.err"
    $params = @{ FilePath = $exe; ArgumentList = $argList; NoNewWindow = $true; PassThru = $true; RedirectStandardOutput = $o; RedirectStandardError = $e }
    if ($stdinFile) { $params.RedirectStandardInput = $stdinFile }
    $p = Start-Process @params
    $null = $p.Handle   # PassThru 로 받은 프로세스는 Handle 을 한 번 건드려야 WaitForExit 뒤 ExitCode 가 null 이 아니다(09-29 실측: 4/4 오판)
    $timedOut = -not $p.WaitForExit($timeoutSec * 1000)
    if ($timedOut) { try { $p.Kill() } catch {}; Log "TIMEOUT ${timeoutSec}s: $exe $($argList[0..2] -join ' ')" }
    $txt = if (Test-Path $o) { Get-Content $o -Raw -Encoding utf8 } else { '' }
    $err = if (Test-Path $e) { (Get-Content $e -Encoding utf8 | Where-Object { $_ -notmatch 'post-quantum|store now|openssh.com/pq|^\s*$' }) -join "`n" } else { '' }
    Remove-Item $o, $e -ErrorAction SilentlyContinue
    if ($err) { Log "stderr($exe): $($err.Substring(0, [Math]::Min(300, $err.Length)))" }
    return @{ out = [string]$txt; code = $(if ($timedOut) { -1 } else { $p.ExitCode }) }
}

# codex.exe — 설치 폴더의 bin 은 정션 2단(bin → packages\standalone\current → releases\<버전>)이라
# 비대화형 세션에서 정션 경로로 부르면 「신뢰할 수 없는 탑재 지점」으로 거부된다. 실제 파일까지 따라간다.
function ResolveCodex {
    $p = Get-Item "$env:LOCALAPPDATA\Programs\OpenAI\Codex\bin" -Force -ErrorAction SilentlyContinue
    $guard = 0
    while ($p -and $p.LinkType -and $p.Target -and $guard -lt 5) { $p = Get-Item ($p.Target | Select-Object -First 1) -Force -ErrorAction SilentlyContinue; $guard++ }
    if ($p) { $exe = Join-Path $p.FullName 'codex.exe'; if (Test-Path $exe) { return $exe } }
    $rel = Get-ChildItem "$env:USERPROFILE\.codex\packages\standalone\releases" -Directory -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if ($rel) { $exe = Join-Path $rel.FullName 'bin\codex.exe'; if (Test-Path $exe) { return $exe } }
    return $null
}

# 1) pull ------------------------------------------------------------------
if (-not $SkipPull) {
    foreach ($r in @($repo, $board)) { [void](RunCapture 'git' @('-C', $r, 'pull', '-q', '--ff-only') $null 120) }
}
$headErp   = (RunCapture 'git' @('-C', $repo,  'rev-parse', '--short', 'HEAD')).out.Trim()
$headBoard = (RunCapture 'git' @('-C', $board, 'rev-parse', '--short', 'HEAD')).out.Trim()
Log "car-erp=$headErp board=$headBoard"

# 2) --verify 4종 (읽기 전용) ---------------------------------------------------
if (-not $env:NOTION_TOKEN) { $env:NOTION_TOKEN = [Environment]::GetEnvironmentVariable('NOTION_TOKEN', 'User') }
if (-not $env:NOTION_TOKEN) { Log 'NOTION_TOKEN 없음 — 중단'; "# Notion 순찰 — $stamp`n`nNOTION_TOKEN 이 회사 PC User 환경변수에 없습니다. 순찰 중단." | Out-File $report -Encoding utf8; exit 1 }

$targets = @(
    @{ name = 'car-erp 기능 카드';     cwd = $repo;  script = "$repo\scripts\notion-cards\publish.php" },
    @{ name = 'car-erp 부서 가이드';   cwd = $repo;  script = "$repo\scripts\notion-guide-publish.php" },
    @{ name = 'car-erp 워크플로우·에러'; cwd = $repo;  script = "$repo\scripts\notion-workflow-lock-guide.php" },
    @{ name = 'board 기능 카드';       cwd = $board; script = "$board\scripts\notion-cards\publish.php" }
)

function RunVerify {
    $sb = New-Object System.Text.StringBuilder
    $mismatch = 0; $blocked = @()
    foreach ($t in $targets) {
        Push-Location $t.cwd
        try { $r = RunCapture $php @($t.script, '--verify') $null 300 } finally { Pop-Location }
        $lines = ($r.out -split "`r?`n")
        $tail = if ($lines.Count -gt 80) { @('… (앞부분 생략)') + $lines[-80..-1] } else { $lines }
        [void]$sb.AppendLine("### $($t.name) (exit $($r.code))")
        [void]$sb.AppendLine(($tail -join "`n").Trim())
        [void]$sb.AppendLine('')
        if ($r.code -ne 0) { $mismatch++ }
        # 알려진 정상: ERP 허브엔 「영업」 페이지가 없다(빌더에만 있음, 영업은 board 를 쓴다) — 매일 뜨면 소음이라 뺀다.
        $blocked += ($lines | Where-Object { $_ -match 'running log|페이지 없음|NOTION_TOKEN|허브 없음' -and $_ -notmatch '영업 — 페이지 없음' })
        Log "$($t.name): exit $($r.code)"
    }
    return @{ text = $sb.ToString(); mismatch = $mismatch; blocked = $blocked }
}

$v1 = RunVerify
$v1.text | Out-File $verifyF -Encoding utf8

$rep = New-Object System.Text.StringBuilder
[void]$rep.AppendLine("# Notion 발행 순찰 — $stamp")
[void]$rep.AppendLine("(회사 PC 자동 실행 · car-erp $headErp · board $headBoard · 모드 = $(if ($Apply) { 'APPLY' } else { 'VERIFY-ONLY(쓰기 0)' }))")
[void]$rep.AppendLine('')
[void]$rep.AppendLine("[요약] 불일치 스크립트 $($v1.mismatch)/4" + $(if ($v1.blocked.Count) { " · 자동 발행 불가 신호 $($v1.blocked.Count)줄" } else { '' }))
if ($v1.blocked.Count) { [void]$rep.AppendLine(($v1.blocked | ForEach-Object { '  ⛔ ' + $_.Trim() }) -join "`n") }
[void]$rep.AppendLine('')
[void]$rep.AppendLine('## 1차 --verify')
[void]$rep.AppendLine($v1.text)

# 3) Codex 발행 (-Apply + 불일치 있을 때만) ------------------------------------
if ($Apply -and $v1.mismatch -gt 0) {
    $codex = ResolveCodex
    if (-not $codex) { Log 'codex.exe 못 찾음'; [void]$rep.AppendLine('## Codex`n`ncodex.exe 를 찾지 못해 발행을 건너뜀.') }
    else {
        $promptFile = "$base\tmp\notion-prompt-$today.txt"
        $lastMsg    = "$base\tmp\notion-codex-last-$today.txt"
        (Get-Content "$base\notion-prompt.md" -Raw -Encoding utf8).Replace('{{VERIFY_PATH}}', $verifyF).Replace('{{DATE}}', $stamp).Replace('{{PHP}}', $php) | Out-File $promptFile -Encoding utf8
        Remove-Item $lastMsg -ErrorAction SilentlyContinue
        Log "codex start: $codex"
        $c = RunCapture $codex @('exec', '-C', $htdocs, '--skip-git-repo-check', '-s', 'workspace-write', '-c', 'sandbox_workspace_write.network_access=true', '--color', 'never', '-o', $lastMsg, '-') $promptFile 1800
        Log "codex exit $($c.code)"
        [void]$rep.AppendLine('## Codex 발행 결과')
        [void]$rep.AppendLine($(if (Test-Path $lastMsg) { (Get-Content $lastMsg -Raw -Encoding utf8).Trim() } else { "(마지막 메시지 없음 · exit $($c.code))" }))
        [void]$rep.AppendLine('')
        $v2 = RunVerify
        [void]$rep.AppendLine("## 2차 --verify (발행 뒤) — 불일치 스크립트 $($v2.mismatch)/4")
        [void]$rep.AppendLine($v2.text)
    }
}
elseif ($Apply) { [void]$rep.AppendLine('## Codex`n`n불일치 0 — 발행할 것 없음.') }
else { [void]$rep.AppendLine('## Codex`n`n관찰 모드(-Apply 없음) — 발행하지 않음. 불일치 목록은 위 1차 --verify.') }

$rep.ToString() | Out-File $report -Encoding utf8
Log "report written ($((Get-Item $report).Length) bytes)"

# 4) 텔레그램 (관찰 기간엔 끔) — heymanerp notify:send 경유, 토큰은 서버에만 -------------
if ($SendTelegram) {
    $bodyFile = "$base\tmp\notion-body-$today.txt"
    $short = "[요약] 불일치 $($v1.mismatch)/4 · 모드 $(if ($Apply) { 'APPLY' } else { 'VERIFY' })`n" + (($v1.blocked | Select-Object -First 5 | ForEach-Object { '⛔ ' + $_.Trim() }) -join "`n") + "`n상세 = 회사 PC $report"
    $short | Out-File $bodyFile -Encoding utf8
    [void](RunCapture 'ssh' @('-i', $key, '-o', 'BatchMode=yes', 'ubuntu@52.79.200.151', "cd /var/www/car-erp && php artisan notify:send --title='Notion 순찰' --body=-") $bodyFile 120)
    Log 'telegram sent via heymanerp notify:send'
}
Log 'done'
