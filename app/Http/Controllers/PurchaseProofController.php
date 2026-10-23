<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PurchaseBalancePayment;
use Illuminate\Support\Facades\Storage;

/**
 * 📎 매입 지급 이체확인증 — 딜러 알림톡 「이체확인증 보기」 버튼이 여는 만료 서명 링크 (jin 2026-10-23).
 *
 * 딜러는 ERP 계정이 없어 로그인 없이 연다(`signed` 미들웨어가 인가, 발급측 7일 만료).
 * 링크가 지급 행(PBP)에 묶여 다른 지급의 파일은 못 연다. 열람은 감사로그에 남긴다(로그인 없음 → user 없음).
 * 본뜬 것 = `BuyerDocumentController::businessRegistration`(사업자등록증 링크).
 */
class PurchaseProofController extends Controller
{
    public const LINK_TTL_DAYS = 7;

    public function show(PurchaseBalancePayment $pbp)
    {
        $path = $pbp->proof_path;
        abort_if(blank($path), 404, '이체확인증이 없습니다.');

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        abort_unless($disk->exists($path), 404, '파일을 찾을 수 없습니다.');

        AuditLog::recordEvent($pbp, 'purchase_proof_viewed');

        $name = basename($path);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($name).'"',
        ]);
    }
}
