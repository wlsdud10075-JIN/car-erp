<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Sign contract · {{ $contract->contract_no }}</title>
    <style>
        * { box-sizing: border-box; }
        body { background:#f3f4f6; margin:0; font-family:system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif; color:#111827; }
        .wrap { max-width:820px; margin:0 auto; padding:16px; }
        h1 { font-size:18px; margin:0 0 2px; }
        .sub { color:#6b7280; font-size:13px; margin:0 0 14px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:14px; }
        .meta { font-size:14px; color:#374151; }
        .meta b { color:#111827; }
        iframe { width:100%; height:60vh; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
        label { display:block; font-size:13px; color:#374151; margin:12px 0 4px; font-weight:600; }
        input[type=text], input[type=email] { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-size:15px; }
        .padwrap { border:1px dashed #cbd5e1; border-radius:8px; background:#fff; position:relative; touch-action:none; }
        canvas { width:100%; height:180px; display:block; border-radius:8px; }
        .padhint { position:absolute; left:12px; top:12px; color:#9ca3af; font-size:13px; pointer-events:none; }
        .row { display:flex; gap:8px; margin-top:10px; }
        .btn { border:0; border-radius:10px; padding:14px; font-size:16px; font-weight:700; cursor:pointer; }
        .primary { flex:1; background:#4c3fb1; color:#fff; }
        .ghost { background:#fff; color:#374151; border:1px solid #d1d5db; }
        .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:8px; padding:10px; font-size:14px; margin-bottom:12px; }
        .note { color:#6b7280; font-size:12px; margin-top:10px; line-height:1.5; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Sales Contract · {{ $contract->contract_no }}</h1>
    <p class="sub">Please review the contract below and sign · 아래 계약서를 확인하고 서명해 주세요.</p>

    @if(! empty($error))
        <div class="err">{{ $error }}</div>
    @endif

    <div class="card">
        <div class="meta">
            <b>{{ data_get($contract->snapshot_data, 'buyer_name') }}</b>
            &nbsp;·&nbsp; {{ data_get($contract->snapshot_data, 'currency', $contract->currency) }}
            &nbsp;·&nbsp; {{ (int) data_get($contract->snapshot_data, 'vehicle_count', 0) }} vehicle(s)
        </div>
    </div>

    <div class="card">
        <iframe src="{{ $previewUrl }}" title="Contract preview"></iframe>
    </div>

    <form class="card" method="post" action="{{ $submitUrl }}" id="signForm">
        <label for="signer_name">Your name / 서명자 성명</label>
        <input type="text" id="signer_name" name="signer_name" maxlength="255"
               value="{{ data_get($contract->snapshot_data, 'buyer_name') }}">

        <label for="recipient_email">Email (signed copy will be sent here) / 이메일</label>
        <input type="email" id="recipient_email" name="recipient_email" maxlength="255" required
               value="{{ $contract->recipient_email }}">

        <label>Signature / 서명</label>
        <div class="padwrap">
            <canvas id="pad"></canvas>
            <span class="padhint" id="padhint">Draw your signature here · 여기에 서명하세요</span>
        </div>
        <div class="row">
            <button type="button" class="btn ghost" id="clearBtn">Clear · 지우기</button>
            <button type="submit" class="btn primary" id="submitBtn">Sign &amp; submit · 서명 제출</button>
        </div>

        <input type="hidden" name="signature" id="signature">
        <p class="note">
            By signing, you agree the electronic signature is legally equivalent to a handwritten one.
            A signed copy is emailed to you as delivery evidence. ·
            서명 시 전자서명은 자필 서명과 동일한 효력을 가지며, 서명본이 이메일로 전달됩니다.
        </p>
    </form>
</div>

<script>
(function () {
    var canvas = document.getElementById('pad');
    var hint = document.getElementById('padhint');
    var ctx = canvas.getContext('2d');
    var drawing = false, dirty = false, last = null;

    function resize() {
        var ratio = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#14285f';
    }
    resize();
    window.addEventListener('resize', resize);

    function pos(e) {
        var rect = canvas.getBoundingClientRect();
        var t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    }
    function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
    function move(e) {
        if (!drawing) return;
        var p = pos(e);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last = p; dirty = true; hint.style.display = 'none'; e.preventDefault();
    }
    function end() { drawing = false; }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);

    document.getElementById('clearBtn').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height); dirty = false; hint.style.display = '';
    });

    var submitted = false;
    document.getElementById('signForm').addEventListener('submit', function (e) {
        if (!dirty) { e.preventDefault(); alert('Please sign first · 먼저 서명해 주세요.'); return; }
        if (submitted) { e.preventDefault(); return; }   // 중복 클릭 방지
        document.getElementById('signature').value = canvas.toDataURL('image/png');
        submitted = true;
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').textContent = 'Submitting… · 제출 중…';
    });
})();
</script>
</body>
</html>
