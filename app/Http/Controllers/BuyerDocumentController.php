<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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

    /**
     * 사업자등록증 전달 (jin 2026-09-11) — 세금계산서 발행 요청 알림톡의 링크.
     *
     * 🚫 알림톡은 파일 첨부가 안 된다(AlimtalkTemplates:224) — 그래서 말소등록증과 같은
     *    「만료 서명 링크」 방식이다.
     *
     * ⚠️ 파일은 **회사 단위**(Setting `biz_cert_{set}`)인데 라우트에 차량을 바인딩한다.
     *    파일 선택에는 안 쓰지만 **어느 차의 세금계산서 요청이었는지**가 접근 로그에 남아야 하고,
     *    링크마다 서명이 갈려 「한 번 받은 링크로 영원히 연다」를 막는다.
     *
     * 🚫 `VehicleDocUrl::for()` 를 쓰지 말 것 — S3 임시 URL 유효시간이 3분이라 알림톡 링크로 못 쓴다.
     */
    public function businessRegistration(Vehicle $vehicle, Request $request)
    {
        $set = Setting::companyTemplateSet();
        $path = Setting::get('biz_cert_'.$set);
        abort_if(blank($path), 404, '사업자등록증이 아직 등록되지 않았습니다.');

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        abort_unless($disk->exists($path), 404, '파일을 찾을 수 없습니다.');

        Log::info('buyer business registration link accessed', [
            'vehicle_id' => $vehicle->id,
            'vehicle_number' => $vehicle->vehicle_number,
            'set' => $set,
            'ip' => $request->ip(),
        ]);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="business-registration.pdf"',
        ]);
    }
}
