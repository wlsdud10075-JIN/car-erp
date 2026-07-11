<?php

namespace App\Http\Controllers;

use App\Mail\VehicleDocumentMail;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\SignedContract;
use App\Services\Documents\SignedContractRenderer;
use App\Support\CompanyMailConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * 판매계약서 전자서명 (2026-07-10 풀회의, ERP 직접호스팅 + Certificate of Completion, 옵션 A).
 *
 * 인가 = URL 서명(`signed` 미들웨어, 만료·변조불가) + sign_token(추측불가 DB 핸들) 병행. 로그인 없음(바이어는 계정 없음).
 * signed 통과해도 business status(revoked/expired/이미 signed) 재검사 → 안내 페이지(403/500 아님).
 * 서명 제출만 1회·불변(멱등), GET 열람은 만료 전 반복. CSRF 제외 = bootstrap/app.php(sign/*).
 */
class SignController extends Controller
{
    private const SIG_MAX_BYTES = 2_000_000;   // canvas 서명 PNG 상한(soffice DoS 방지)

    private const SIG_MAX_W = 3000;

    private const SIG_MAX_H = 1200;

    private function resolve(string $token): SignedContract
    {
        return SignedContract::where('sign_token', $token)->firstOrFail();
    }

    /** 서명 페이지 — 계약 PDF 미리보기 + 서명패드 + 이메일칸. 상태 변경은 pending→viewed(최초 열람)만. */
    public function show(Request $request, string $token)
    {
        $contract = $this->resolve($token);

        if ($contract->isSigned()) {
            return response()->view('sign.done', ['contract' => $contract]);
        }
        if (! $contract->isSignable()) {
            return response()->view('sign.closed', ['contract' => $contract]);
        }

        if ($contract->status === SignedContract::STATUS_PENDING) {
            $contract->update(['status' => SignedContract::STATUS_VIEWED, 'viewed_at' => now()]);
            $this->logAccess($contract, $request, 'viewed');
        }

        return view('sign.show', [
            'contract' => $contract,
            'previewUrl' => URL::temporarySignedRoute('sign.preview', now()->addHours(2), ['token' => $token]),
            'submitUrl' => URL::temporarySignedRoute('sign.submit', now()->addHours(2), ['token' => $token]),
        ]);
    }

    /** 발급 시 캐시한 계약 미리보기 PDF inline(iframe src). soffice 재렌더 없음. */
    public function preview(string $token)
    {
        $contract = $this->resolve($token);
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $path = $contract->previewPdfPath();
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contract-preview.pdf"',
        ]);
    }

    /** 서명 확정 — 서명본(계약+CoC 단일 PDF) 렌더·봉인 후 증거메일. 멱등(이미 signed=no-op 안내). */
    public function submit(Request $request, string $token)
    {
        $contract = $this->resolve($token);

        if ($contract->isSigned()) {
            return response()->view('sign.done', ['contract' => $contract]);
        }
        if (! $contract->isSignable()) {
            return response()->view('sign.closed', ['contract' => $contract]);
        }

        try {
            $validated = $request->validate([
                'signature' => ['required', 'string'],
                'signer_name' => ['nullable', 'string', 'max:255'],
                'recipient_email' => ['required', 'email', 'max:255'],
            ]);
            $png = $this->decodeSignaturePng($validated['signature']);
        } catch (ValidationException $e) {
            return response()->view('sign.show', [
                'contract' => $contract,
                'previewUrl' => URL::temporarySignedRoute('sign.preview', now()->addHours(2), ['token' => $token]),
                'submitUrl' => URL::temporarySignedRoute('sign.submit', now()->addHours(2), ['token' => $token]),
                'error' => $e->validator->errors()->first() ?: __('signed_contract.sign.invalid'),
            ], 422);
        }

        // ① 서명 이미지 저장 + 서명본 렌더 → ② status=signed 먼저 커밋(증거 확정) → ③ 메일은 별도 시도.
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $sigPath = preg_replace('/\.xlsx$/i', '.signature.png', $contract->snapshot_path);
        $disk->put($sigPath, $png);

        // 서명자 캡처를 먼저 반영(렌더 CoC 가 읽음)
        $contract->forceFill([
            'signer_name' => $validated['signer_name'] ?? null,
            'recipient_email' => $validated['recipient_email'],
            'signer_ip' => $request->ip(),
            'signer_ua' => substr((string) $request->userAgent(), 0, 1000),
            'signature_path' => $sigPath,
            'signed_at' => now(),
        ])->save();

        $signedPdf = app(SignedContractRenderer::class)->render($contract, $png);
        $signedPath = preg_replace('/\.xlsx$/i', '.signed.pdf', $contract->snapshot_path);
        $disk->put($signedPath, $signedPdf);

        $contract->forceFill([
            'status' => SignedContract::STATUS_SIGNED,
            'signed_pdf_path' => $signedPath,
            'signed_hash' => hash('sha256', $signedPdf),
        ])->save();

        $this->logAccess($contract, $request, 'signed');
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => $contract::class,
            'auditable_id' => $contract->id,
            'action' => 'contract_signed',
            'ip_address' => $request->ip(),
        ]);

        // ③ 증거 메일 — 실패해도 서명은 유지(재발송 대상). SMTP 지연이 서명 POST 를 매달지 않게 별도 try.
        $this->sendEvidenceEmail($contract, $signedPath);

        return response()->view('sign.done', ['contract' => $contract->fresh()]);
    }

    private function sendEvidenceEmail(SignedContract $contract, string $signedPath): void
    {
        $to = $contract->recipient_email;
        if (! $to) {
            return;
        }
        try {
            $cfg = CompanyMailConfig::active();
            if (! $cfg->isConfigured()) {
                return;   // 회사 메일 미설정 — 서명은 유지, 재발송 버튼으로 추후
            }
            $mail = (new VehicleDocumentMail(
                subjectLine: __('signed_contract.mail.subject', ['no' => $contract->contract_no]),
                bodyText: __('signed_contract.mail.body', ['no' => $contract->contract_no]),
                storedFiles: [['path' => $signedPath, 'name' => $contract->contract_no.'.pdf']],
            ))->to($to);
            $cfg->send($mail);
            $contract->forceFill(['mail_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('signed contract evidence mail failed', ['id' => $contract->id, 'error' => $e->getMessage()]);
        }
    }

    /** data:image/png;base64,... → 검증된 PNG 바이트. 불신 입력(거대/비PNG) 방어. */
    private function decodeSignaturePng(string $dataUri): string
    {
        if (! preg_match('#^data:image/png;base64,#i', $dataUri)) {
            throw ValidationException::withMessages(['signature' => __('signed_contract.sign.invalid')]);
        }
        $bytes = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if ($bytes === false || strlen($bytes) < 8 || strlen($bytes) > self::SIG_MAX_BYTES) {
            throw ValidationException::withMessages(['signature' => __('signed_contract.sign.invalid')]);
        }
        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw ValidationException::withMessages(['signature' => __('signed_contract.sign.invalid')]);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] > self::SIG_MAX_W || $info[1] > self::SIG_MAX_H) {
            throw ValidationException::withMessages(['signature' => __('signed_contract.sign.invalid')]);
        }

        return $bytes;
    }

    private function logAccess(SignedContract $contract, Request $request, string $event): void
    {
        foreach (($contract->vehicle_ids ?? []) as $vehicleId) {
            DocumentAccessLog::create([
                'user_id' => null,
                'vehicle_id' => (int) $vehicleId,
                'document_type' => 'sales_contract_'.$event,   // signing_viewed / signing_signed 구분
                'ip_address' => $request->ip(),
                'source' => 'signing',
                'actor_email' => $contract->recipient_email,
            ]);
        }
    }
}
