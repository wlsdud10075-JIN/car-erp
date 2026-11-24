<?php

namespace App\Services;

use App\Mail\VehicleDocumentMail;
use App\Models\AuditLog;
use App\Models\BankRemittance;
use App\Models\MailDeliveryLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\PdfConverter;
use App\Support\CompanyMailConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 🏦 은행 서류 (jin 2026-11-24) — 전신문을 받아 ①송금인 이름을 차들의 기본정보 송금인 칸에 덮어쓰고
 * ②은행용 Proforma(일반 PI 와 같고 `Name` 칸만 송금인)를 만들어 ③신한 담당자에게 메일로 보낸다.
 * 서류탭 「은행」 영역이 차마다 기록을 보여 준다. 판매탭 입금 줄 전신문과는 별개.
 */
class BankRemittanceService
{
    public const MAX_VEHICLES = 30;

    public const ACCEPT = ['tif', 'tiff', 'pdf', 'png', 'jpg', 'jpeg'];

    /** 은행용 PI 에서 송금인 이름이 들어가는 칸 — 3사 양식 공통 `D5 Name`(일반 PI 는 바이어 이름). */
    public const NAME_CELL = 'E5';

    /**
     * 받는 사람 목록 = 기능설정 회사별 `bank_mail_recipients_{set}`(JSON, 은행·이름·메일 — jin 11-24 «한 사람이 아니야»).
     * 🚫 코드에 주소를 박지 않는다. 옛 한 칸(`bank_mail_to_{set}`)만 있으면 그 주소 하나로 읽는다.
     *
     * @return list<array{bank: string, name: string, email: string}>
     */
    public static function recipients(): array
    {
        $set = Setting::companyTemplateSet();
        $rows = json_decode((string) (Setting::get("bank_mail_recipients_{$set}", '') ?: ''), true);
        if (! is_array($rows)) {
            $old = trim((string) (Setting::get("bank_mail_to_{$set}", '') ?: ''));
            $rows = $old !== '' ? [['bank' => '', 'name' => '', 'email' => $old]] : [];
        }

        return collect($rows)
            ->filter(fn ($r) => is_array($r) && filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL))
            ->map(fn ($r) => ['bank' => trim((string) ($r['bank'] ?? '')), 'name' => trim((string) ($r['name'] ?? '')), 'email' => trim((string) $r['email'])])
            ->unique('email')->values()->all();
    }

    /** 드롭박스·기록 표시용 「신한은행 · 홍길동 <a@b.com>」. 목록에 없는 주소는 주소 그대로. */
    public static function recipientLabel(string $email): string
    {
        return self::recipientOptions()[$email] ?? $email;
    }

    /** @return array<string, string> 메일 => 표시 이름 (공용 콤보 `extra`) */
    public static function recipientOptions(): array
    {
        return collect(self::recipients())->mapWithKeys(function ($r) {
            $head = implode(' · ', array_filter([$r['bank'], $r['name']], fn ($s) => $s !== ''));

            return [$r['email'] => $head !== '' ? "{$head} <{$r['email']}>" : $r['email']];
        })->all();
    }

    public static function canUse(?User $user): bool
    {
        return (bool) $user?->canConfirmFinance();   // 재무 + 관리 이상 (jin 11-24)
    }

    /**
     * 고른 차를 불러 검사한다. 실패면 번역 키(문자열)를 돌려준다.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Vehicle>|string
     */
    public static function vehiclesFor(User $user, array $ids): Collection|string
    {
        if (! self::canUse($user)) {
            return 'vehicle.bank.err_permission';
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 'vehicle.bank.err_none';
        }
        if (count($ids) > self::MAX_VEHICLES) {
            return 'vehicle.bank.err_max';
        }
        $byId = Vehicle::with('buyer')->whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();
        if ($vehicles->count() !== count($ids) || ! $vehicles->every(fn (Vehicle $v) => $user->canScopeVehicle($v))) {
            return 'vehicle.bank.err_scope';
        }
        // Proforma 는 1바이어·단일통화(VehicleDocumentController::HOMOGENEOUS_TYPES 와 같은 규칙)
        if ($vehicles->contains(fn (Vehicle $v) => ! $v->buyer_id)) {
            return 'vehicle.bank.err_no_buyer';
        }
        if ($vehicles->pluck('buyer_id')->unique()->count() > 1) {
            return 'vehicle.bank.err_buyers';
        }
        if ($vehicles->pluck('currency')->unique()->count() > 1) {
            return 'vehicle.bank.err_currency';
        }

        return $vehicles;
    }

    /**
     * 전신문 저장 + 송금인 이름 덮어쓰기 + 은행용 PI 생성. 성공하면 기록을 돌려준다.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     * @param  array{name: ?string, status: ?string}  $ocr
     */
    public static function create(User $user, Collection $vehicles, UploadedFile $file, string $remitterName, array $ocr = []): BankRemittance
    {
        $disk = config('filesystems.vehicle_docs_disk');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs('bank-remittances/'.now()->format('Ym'), Str::uuid().'.'.$ext, $disk);
        if (! $path || ! Storage::disk($disk)->exists($path)) {   // §8 #47 — 쓰기 실패는 예외가 안 난다
            throw new \RuntimeException('전신문 저장 실패');
        }

        return DB::transaction(function () use ($user, $vehicles, $file, $path, $remitterName, $ocr) {
            $rec = BankRemittance::create([
                'remittance_path' => $path,
                'remittance_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'remitter_name' => $remitterName,
                'ocr_name' => $ocr['name'] ?? null,
                'ocr_status' => $ocr['status'] ?? null,
                'created_by' => $user->id,
            ]);
            $rec->vehicles()->attach($vehicles->pluck('id')->all());

            // 기본정보 탭 송금인 칸 — 전신문 값으로 덮어쓴다(jin «전신문 Names 가 정확»). 모델 저장이라 감사로그가 남는다.
            foreach ($vehicles as $v) {
                if ($v->remitter_name !== $remitterName) {
                    $v->update(['remitter_name' => $remitterName]);
                }
            }

            self::buildInvoice($rec, $vehicles);

            return $rec;
        });
    }

    /**
     * 은행용 Proforma — 일반 PI 그대로 만들고 `Name`(E5)만 송금인 이름. PDF(LibreOffice) → 실패하면 xlsx.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    public static function buildInvoice(BankRemittance $rec, Collection $vehicles): void
    {
        $filler = new DocumentFiller($vehicles);
        $sheet = $filler->spreadsheet('invoice');
        $sheet->getSheetByName('Invoice')?->setCellValue(self::NAME_CELL, $rec->remitter_name);
        $base = 'BANK_'.preg_replace('/\.xlsx$/i', '', $filler->filename('invoice'));

        try {
            $binary = app(PdfConverter::class)->fromSpreadsheet($sheet);
            $name = $base.'.pdf';
        } catch (\Throwable $e) {
            report($e);
            $writer = new Xlsx($sheet);
            $writer->setPreCalculateFormulas(false);
            ob_start();
            $writer->save('php://output');
            $binary = (string) ob_get_clean();
            $name = $base.'.xlsx';
        }

        $disk = config('filesystems.vehicle_docs_disk');
        $path = 'bank-remittances/'.now()->format('Ym').'/'.Str::uuid().'_'.$name;
        Storage::disk($disk)->put($path, $binary);
        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Proforma 저장 실패');
        }
        $rec->update(['invoice_path' => $path, 'invoice_name' => $name]);
    }

    /**
     * 서류탭 × (jin 11-24 «삭제 조항이 없으니 계속 쌓인다») — 이 차에서만 뗀다. 여러 대가 한 기록을 나눠 쓰므로,
     * 마지막 차였을 때만 기록과 파일 2개(전신문·은행용 PI)를 지운다. 기본정보 송금인 칸과 메일 발송 로그는 그대로 둔다.
     */
    public static function removeFromVehicle(User $user, Vehicle $vehicle, int $recordId): void
    {
        abort_unless(self::canUse($user) && $user->canScopeVehicle($vehicle), 403);
        $rec = $vehicle->bankRemittances()->whereKey($recordId)->firstOrFail();

        DB::transaction(function () use ($user, $vehicle, $rec) {
            $rec->vehicles()->detach($vehicle->id);
            AuditLog::create([
                'user_id' => $user->id,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicle->id,
                'action' => 'bank_remittance_removed',
                'column_name' => 'bank_remittance',
                'old_value' => "#{$rec->id} {$rec->remitter_name} ({$rec->created_at?->format('Y-m-d H:i')})",
                'new_value' => null,
                'ip_address' => request()?->ip(),
            ]);
            if (! $rec->vehicles()->exists()) {
                $paths = array_values(array_filter([$rec->remittance_path, $rec->invoice_path]));
                $rec->delete();
                DB::afterCommit(fn () => Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($paths));
            }
        });
    }

    /**
     * 은행 담당자에게 전신문 + 은행용 PI 첨부 메일. 실패면 예외(호출측 토스트). 로그는 차마다 1행.
     */
    public static function send(BankRemittance $rec, User $user, string $to, string $subject, string $body): void
    {
        $company = CompanyMailConfig::active();
        if (! $company->isConfigured()) {
            throw new \DomainException('not_configured');
        }
        $files = [['path' => $rec->remittance_path, 'name' => $rec->remittance_name]];
        if ($rec->invoice_path) {
            $files[] = ['path' => $rec->invoice_path, 'name' => $rec->invoice_name];
        }
        $subject = trim($subject) !== '' ? trim($subject) : $company->companyLabel();
        $mailable = (new VehicleDocumentMail($subject, $body, $files))->to($to);
        $vehicleIds = $rec->vehicles()->pluck('vehicles.id')->all();
        $log = fn (string $status, ?string $err) => collect($vehicleIds)->each(fn ($vid) => MailDeliveryLog::create([
            'vehicle_id' => $vid, 'user_id' => $user->id, 'channel' => $company->channel, 'from_address' => $company->fromAddress,
            'to_email' => $to, 'subject' => $subject, 'document_names' => array_column($files, 'name'), 'status' => $status, 'error' => $err,
        ]));

        try {
            $company->send($mailable);
        } catch (\Throwable $e) {
            $log('failed', mb_substr($e->getMessage(), 0, 500));
            throw $e;
        }
        $log('sent', null);
        $rec->update(['mailed_to' => $to, 'mailed_at' => now()]);
    }
}
