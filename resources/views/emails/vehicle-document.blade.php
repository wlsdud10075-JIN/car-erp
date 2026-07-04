<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:0; background:#f6f6f8;">
    <div style="max-width:600px; margin:0 auto; padding:24px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.7; color:#222;">
        @if (trim($bodyText) !== '')
            {!! nl2br(e($bodyText)) !!}
        @else
            <p>Please find the attached document(s).</p>
        @endif
        <hr style="border:none; border-top:1px solid #e5e5e5; margin:24px 0;">
        <p style="font-size:12px; color:#888;">{{ config('company.name_en', 'SSANCAR CO., LTD.') }}</p>
    </div>
</body>
</html>
