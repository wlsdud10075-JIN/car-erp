<?php

namespace App\Services\Documents;

use App\Models\SignedContract;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 판매계약서 전자서명 세션 발급 (2026-07-10 풀회의 — 옵션 A).
 *
 * 발송 시점 원본을 렌더해 **동결**한다: xlsx 스냅샷 + source_hash(증거 뿌리) + 미리보기 PDF 캐시
 * (서명 페이지가 매 GET soffice 재렌더하지 않도록 발급 1회만 변환) + snapshot_data(식별 메타 JSON).
 * pending SignedContract + sign_token 생성, Laravel 서명 URL 반환.
 *
 * authZ(차량 스코프)는 caller 책임 — 이 서비스는 도메인 검증(동일 바이어·통화·export·대수)만 한다.
 * 회의록 docs/meetings/2026-07-10-sales-contract-e-signature.md
 */
class SigningSessionService
{
    private const MAX = 30;

    private const TTL_DAYS = 7;

    /**
     * @param  Collection<int, Vehicle>  $vehicles  선택 순서 보존
     * @return array{contract: SignedContract, url: string}
     *
     * @throws ValidationException 도메인 검증 실패(혼합 바이어/통화·non-export·바이어 미지정 등)
     */
    public function issue(Collection $vehicles, ?string $recipientEmail, ?int $createdBy): array
    {
        $vehicles = $vehicles->filter()->values();
        $this->validate($vehicles);

        $buyer = $vehicles->first()->buyer;                        // 동일 바이어 검증 후라 primary = 전체
        $currency = (string) ($vehicles->first()->currency ?? 'USD');
        $primaryId = (int) $vehicles->first()->id;
        $contractNo = 'SC'.now()->format('ym').'-'.str_pad((string) $primaryId, 5, '0', STR_PAD_LEFT);

        // 발송 시점 원본 렌더 (1회) → xlsx 바이트 동결 + 미리보기 PDF 캐시
        $spreadsheet = (new DocumentFiller($vehicles))->spreadsheet('sales_contract');
        $xlsxBytes = $this->xlsxBytes($spreadsheet);
        $sourceHash = hash('sha256', $xlsxBytes);
        $pdfBytes = app(PdfConverter::class)->fromSpreadsheet($spreadsheet);

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $uuid = (string) Str::uuid();
        $xlsxPath = "signed-contracts/{$uuid}.xlsx";
        $disk->put($xlsxPath, $xlsxBytes);
        $disk->put(SignedContract::previewPathFor($xlsxPath), $pdfBytes);

        // 재발급 = 겹치는 활성세션 revoke (활성 1개 불변식). signed 는 보존(하드삭제 가드).
        $this->revokeOverlappingActive($vehicles->pluck('id')->map(fn ($x) => (int) $x)->all());

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays(self::TTL_DAYS);

        $contract = SignedContract::create([
            'buyer_id' => $buyer?->id,
            'vehicle_ids' => $vehicles->pluck('id')->map(fn ($x) => (int) $x)->values()->all(),
            'contract_no' => $contractNo,
            'currency' => $currency,
            'snapshot_path' => $xlsxPath,
            'source_hash' => $sourceHash,
            'snapshot_data' => $this->snapshotData($vehicles, $buyer?->name, $currency, $contractNo),
            'status' => SignedContract::STATUS_PENDING,
            'sign_token' => $token,
            'token_expires_at' => $expiresAt,
            'recipient_email' => $recipientEmail ?: $buyer?->contact_email,
            'sent_at' => now(),
            'created_by' => $createdBy,
        ]);

        return [
            'contract' => $contract,
            'url' => URL::temporarySignedRoute('sign.show', $expiresAt, ['token' => $token]),
        ];
    }

    /** @param  Collection<int, Vehicle>  $vehicles */
    private function validate(Collection $vehicles): void
    {
        if ($vehicles->isEmpty()) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.empty')]);
        }
        if ($vehicles->count() > self::MAX) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.too_many', ['max' => self::MAX])]);
        }
        if (! $vehicles->every(fn (Vehicle $v) => $v->sales_channel === 'export')) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.export_only')]);
        }
        if ($vehicles->pluck('buyer_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.mixed_buyer')]);
        }
        if ($vehicles->pluck('currency')->unique()->count() > 1) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.mixed_currency')]);
        }
        if (blank($vehicles->first()->buyer_id)) {
            throw ValidationException::withMessages(['vehicles' => __('signed_contract.issue.no_buyer')]);
        }
    }

    /**
     * 서명 페이지 표시·이메일·감사에 쓰는 식별 메타만 동결한다.
     * 금액 총계는 넣지 않는다 — "무엇에 서명했나"의 authority 는 PDF 스냅샷(재계산 drift 방지).
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    private function snapshotData(Collection $vehicles, ?string $buyerName, string $currency, string $contractNo): array
    {
        return [
            'contract_no' => $contractNo,
            'buyer_name' => $buyerName,
            'currency' => $currency,
            'vehicle_count' => $vehicles->count(),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => [
                'plate' => $v->vehicle_number,
                'brand' => DocValue::brandEn($v),
                'model' => DocValue::carName($v),
                'vin' => $v->nice_reg_vin,
            ])->values()->all(),
        ];
    }

    /** 겹치는(공유 차량) 활성세션 revoke — 한 차량이 두 개의 미서명 계약에 묶이지 않게. */
    private function revokeOverlappingActive(array $vehicleIds): void
    {
        $active = SignedContract::whereIn('status', SignedContract::ACTIVE_STATUSES)->get();
        foreach ($active as $c) {
            if (array_intersect($c->vehicle_ids ?? [], $vehicleIds) !== []) {
                $c->update(['status' => SignedContract::STATUS_REVOKED, 'revoked_at' => now()]);
            }
        }
    }

    private function xlsxBytes(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);   // 수식 재계산은 soffice(PdfConverter)에 위임
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
