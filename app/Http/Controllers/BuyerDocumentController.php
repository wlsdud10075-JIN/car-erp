<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 국내 바이어용 문서 전달 — 알림톡으로 보낸 만료 서명 링크가 여기로 온다.
 *
 * 인가 = URL 서명(`signed` 미들웨어). 로그인 없음(바이어는 ERP 계정이 없다).
 * 서명 대상은 **차량 id + 고정 문서종류(말소등록증)뿐** — 파일 경로는 URL 에 실리지 않아
 * 경로 조작/IDOR 불가. 링크 유효기간(3일)은 발급측(temporarySignedRoute)에서 지정.
 *
 * ⚠️ 발송 사실(누가·언제·어느 차량·어느 번호로 보냈는지)은 alimtalk_logs 에 남는다(권위 감사).
 *    여기 클릭은 별도 스키마 없이 Log 로만 남긴다(document_access_logs 는 user_id 필수라 미사용).
 */
class BuyerDocumentController extends Controller
{
    public function deregistration(Vehicle $vehicle, Request $request)
    {
        abort_if(blank($vehicle->deregistration_document), 404, '말소등록증이 아직 등록되지 않았습니다.');

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $path = $vehicle->deregistration_document;
        abort_unless($disk->exists($path), 404, '파일을 찾을 수 없습니다.');

        Log::info('buyer deregistration link accessed', [
            'vehicle_id' => $vehicle->id,
            'vehicle_number' => $vehicle->vehicle_number,
            'ip' => $request->ip(),
        ]);

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filename = '말소등록증_'.$vehicle->vehicle_number.($ext ? '.'.$ext : '');

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
