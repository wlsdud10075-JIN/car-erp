<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Services\Documents\DocumentFiller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ssancar.com 바이어 포털 — 서류 실물 통로 (2026-08-27, jin 확정 4종).
 * 진입점 = `docs/integration/ssancar-portal-collab.md` · 답신 = `전달패킷_ERP→ssancar_2026-08-27.md`.
 *
 * ═══ 통로가 **두 개**인 이유 — 축은 「생성물이냐 저장물이냐」다 ═══════════════
 *   ① 통관 SET  = **매번 생성**한다 → HMAC 스트림(`clearanceSet`). board 와 같은 모양.
 *                 저장하지 않는 이유: ERP 가 오늘 저장하는 서류 칸은 **전부 외부 원본 스캔**이고,
 *                 통관 SET 은 차량 데이터를 다시 읽어 만드는 살아있는 파일이라 저장하면 낡는다.
 *   ② 수출신고서·선적사진 = **저장된 원본** → 짧은 만료 서명 URL(`files`).
 *                 바이어 브라우저가 S3 에서 직접 받는다. 사이트가 파일을 복사해 두지 않는다(ssancar 요구).
 *
 * 🔑 **게이트 판정은 `Vehicle` 한 곳뿐이다**(`portalDocumentsBlocker` / `clearanceSetBlocker`).
 *    목록 응답의 `documents_open`·`can_download_clearance_set` 와 **여기가 같은 메서드를 부른다** —
 *    조건을 옮겨 적으면 「버튼은 뜨는데 받으면 거절」이 된다(SKILLS §8 #44).
 *    ⚠️ **서빙 시점에 다시 판정한다** — 목록을 받은 뒤 차량이 조건에서 벗어날 수 있다(§8 #26).
 *
 * 🔒 **인가 경계는 HMAC 이다.** `buyer_id` 파라미터는 **인증이 아니라 정합성 검사**다 —
 *    호출자가 스스로 채우는 값이라 신뢰 경계가 못 된다(board 의 `salesman_email` 과 같은 성격).
 *    바이어 본인 확인은 ssancar 가 자기 세션으로 한다. 여기서는 **사이트 버그로 A 의 서류가
 *    B 에게 가는 것**을 막고, 누구 몫으로 나갔는지를 감사에 남긴다.
 *
 * 🚫 여기에 말소 SET·위임장을 얹지 말 것 — 소유자 **성명·주민등록번호·주소**가 들어간다
 *    (`DeregistrationSetMapping`). board 도 같은 이유로 403 이다.
 */
class PortalDocumentController extends Controller
{
    /** 서명 URL 유효시간(초) — 화면이 링크를 받아 곧바로 여는 용도. 길게 두면 감사 우회 창이 커진다. */
    private const LINK_TTL_SECONDS = 180;

    /**
     * ① 통관 SET — 요청 시 생성해서 xlsx 바이트로 돌려준다.
     *
     * ⚠️ `setPreCalculateFormulas(false)` 는 **필수**다. 통관 SET 은 마스터 시트 하나를 채우면
     *    6시트가 `=구매리스트!셀` 로 따라오는 구조라, 재계산을 Excel 에 위임해야 cascade 가 산다.
     */
    public function clearanceSet(Vehicle $vehicle, Request $request): StreamedResponse
    {
        $this->assertBuyerMatches($vehicle, $request);

        // 🔑 목록 플래그와 같은 메서드. 여기서 다시 부르는 것이 핵심이다.
        $blocker = $vehicle->clearanceSetBlocker();
        abort_if($blocker !== null, 422, 'clearance_set_unavailable: '.$blocker);

        $filler = new DocumentFiller($vehicle);
        $spreadsheet = $filler->spreadsheet('clearance');
        $filename = $filler->filename('clearance');

        $this->recordAccess($vehicle, 'clearance', $request);

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * ② 저장된 실물 — 수출신고서 + 선적 사진의 **짧은 만료 서명 URL**.
     *
     * 사진은 `vehicle_photos.id` 로 식별한다 — **경로를 URL 에 싣지 않는다**(경로 조작 차단.
     * `BuyerDocumentController` 가 (차량 + 고정 문서종류)만 서명하는 것과 같은 성질).
     *
     * ⚠️ **확장자·MIME 를 같이 준다.** 선적 사진에 pdf 가 섞여 있어(실측 577 jpg + 2 pdf)
     *    사이트가 전부 `<img>` 로 그리면 그 2건이 깨진다.
     *
     * ⚠️ 감사 — 서명 URL 은 **ERP 를 거치지 않고** S3 에서 바로 열리므로 열람 순간에는 훅이 없다.
     *    그래서 **발급 시점에** `document_access_logs` 를 남긴다. 기록되는 사건은
     *    「열었다」가 아니라 **「이 바이어 몫으로 열람 권한을 내줬다」**다. 그게 인가 사건이라 의미가 있다.
     */
    public function files(Vehicle $vehicle, Request $request): JsonResponse
    {
        $this->assertBuyerMatches($vehicle, $request);

        $blocker = $vehicle->portalDocumentsBlocker();
        abort_if($blocker !== null, 422, 'documents_closed: '.$blocker);

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));

        // 🚨 임시 서명 URL 을 못 만드는 디스크(로컬 public)면 **링크를 내지 않는다.**
        //    `->url()` 은 그 서버 안에서만 뜻이 있는 주소라 외부 바이어에겐 무의미하고,
        //    로컬 public 은 서명도 만료도 없다. 운영 3사는 s3 라 정상 동작한다.
        if (! $disk->providesTemporaryUrls()) {
            return response()->json([
                'error' => 'temporary_urls_unsupported',
                'message' => 'This server stores documents on a disk without signed URLs.',
            ], 503);
        }

        $expiresAt = now()->addSeconds(self::LINK_TTL_SECONDS);
        $out = ['expires_in' => self::LINK_TTL_SECONDS, 'export_declaration' => null, 'shipping_photos' => []];

        if (filled($vehicle->export_declaration_document) && $disk->exists($vehicle->export_declaration_document)) {
            $out['export_declaration'] = $this->fileEntry($disk, $vehicle->export_declaration_document, $expiresAt);
            $this->recordAccess($vehicle, 'export_declaration', $request);
        }

        $photos = VehiclePhoto::where('vehicle_id', $vehicle->id)
            ->where('category', 'shipping')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($photos as $photo) {
            if (! $disk->exists($photo->path)) {
                continue;   // 파일이 사라진 행 — 깨진 링크를 주는 것보다 빼는 게 낫다
            }
            $out['shipping_photos'][] = ['id' => $photo->id]
                + $this->fileEntry($disk, $photo->path, $expiresAt);
        }

        if ($photos->isNotEmpty()) {
            $this->recordAccess($vehicle, 'shipping_photos', $request);
        }

        return response()->json($out);
    }

    /** 한 파일의 링크 + 사이트가 어떻게 그릴지 정하는 데 필요한 것(확장자·MIME). */
    private function fileEntry($disk, string $path, $expiresAt): array
    {
        return [
            'url' => $disk->temporaryUrl($path, $expiresAt),
            'ext' => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            'mime' => $disk->mimeType($path) ?: 'application/octet-stream',
        ];
    }

    /**
     * 🔒 정합성 검사 — 이 차가 그 바이어 것인가.
     *
     * 신뢰 경계가 아니라(위 클래스 주석) **사이트 버그 차단 + 감사 귀속**이다.
     * 그래서 파라미터가 없으면 통과시키지 않는다 — 없으면 누구 몫인지 못 남긴다.
     */
    private function assertBuyerMatches(Vehicle $vehicle, Request $request): void
    {
        $buyerId = (int) $request->query('buyer_id', '0');
        abort_if($buyerId <= 0, 400, 'buyer_id required');
        abort_if((int) $vehicle->buyer_id !== $buyerId, 403, 'Forbidden');
    }

    /**
     * 감사 — board 와 **같은 표**에 남긴다. 감사를 두 곳으로 가르지 않는다(ssancar 요구 §6-2).
     * `user_id` 는 2026-06-18 부터 nullable 이고 `source`·`actor_email` 이 세션 없는 호출을 받는다.
     */
    private function recordAccess(Vehicle $vehicle, string $type, Request $request): void
    {
        DocumentAccessLog::create([
            'user_id' => null,
            'vehicle_id' => $vehicle->id,
            'document_type' => $type,
            'ip_address' => $request->ip(),
            'source' => 'portal_api',
            // 바이어 계정은 ssancar 쪽에 있다 — ERP 가 아는 식별자는 buyer_id 뿐이다.
            'actor_email' => 'buyer:'.(int) $vehicle->buyer_id,
        ]);
    }
}
