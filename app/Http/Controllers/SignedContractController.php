<?php

namespace App\Http\Controllers;

use App\Models\SignedContract;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * ERP 내부 — 서명본(Certificate of Completion 포함 PDF) 열람. 로그인 사용자 전용.
 * 인가 = 계약에 묶인 전 차량을 스코프할 수 있어야(canScopeVehicle, showMulti 와 동일 규칙).
 * 바이어용 공개 signed URL(SignController)과 별개.
 */
class SignedContractController extends Controller
{
    public function pdf(SignedContract $signedContract)
    {
        abort_unless($signedContract->isSigned() && $signedContract->signed_pdf_path, 404, '서명본이 아직 없습니다.');

        $user = auth()->user();
        $vehicles = Vehicle::whereIn('id', $signedContract->vehicle_ids ?? [])->get();
        abort_unless(
            $vehicles->isNotEmpty() && $vehicles->every(fn (Vehicle $v) => $user->canScopeVehicle($v)),
            403,
            '접근 권한이 없는 계약입니다.',
        );

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        abort_unless($disk->exists($signedContract->signed_pdf_path), 404, '파일을 찾을 수 없습니다.');

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $signedContract->contract_no.'.pdf',
            'signed-contract.pdf',
        );

        return response($disk->get($signedContract->signed_pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
