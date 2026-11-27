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
use App\Support\CompanyMailConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    /** 수정본으로 올릴 수 있는 은행용 Proforma — 엑셀로 받아 고친 것, 또는 PDF 로 저장한 것. */
    public const INVOICE_ACCEPT = ['xlsx', 'xls', 'pdf'];

    /** 모아 보내기 — 메일 한 통에 싣는 건수·용량 상한(Gmail 첨부 25MB 아래로). */
    public const BATCH_MAX = 30;

    public const BATCH_MAX_BYTES = 20 * 1024 * 1024;

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
     * 은행용 Proforma — 일반 PI 그대로 만들고 `Name`(E5)만 송금인 이름. **엑셀(.xlsx)** 로 만든다
     * (🔀 jin 2026-11-26 «PDF 는 수정이 어렵다, Proforma 처럼 엑셀로» — 구: PDF 우선, 실패 시 xlsx).
     * 금액 칸은 소수점 두 자리 서식(`decimalAmounts`) — 은행에 맞춰 고쳐 넣을 때 센트까지 보이게(jin 같은 날).
     *
     * @param  Collection<int, Vehicle>  $vehicles
     */
    public static function buildInvoice(BankRemittance $rec, Collection $vehicles): void
    {
        $filler = new DocumentFiller($vehicles);
        $sheet = $filler->spreadsheet('invoice');
        $ws = $sheet->getSheetByName('Invoice');
        $ws?->setCellValue(self::NAME_CELL, $rec->remitter_name);
        if ($ws) {
            self::decimalAmounts($ws);
            self::totalsAsFormulas($ws);
        }
        $base = 'BANK_'.preg_replace('/\.xlsx$/i', '', $filler->filename('invoice'));

        $writer = new Xlsx($sheet);
        $writer->setPreCalculateFormulas(false);
        ob_start();
        $writer->save('php://output');
        $binary = (string) ob_get_clean();
        $name = $base.'.xlsx';

        $disk = config('filesystems.vehicle_docs_disk');
        $path = 'bank-remittances/'.now()->format('Ym').'/'.Str::uuid().'_'.$name;
        Storage::disk($disk)->put($path, $binary);
        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Proforma 저장 실패');
        }
        $rec->update(['invoice_path' => $path, 'invoice_name' => $name]);
    }

    /**
     * 금액 칸 서식에 소수점 두 자리를 붙인다 — 양식의 `$#,##0`·`€#,##0` 같은 정수 서식을 `…#,##0.00` 으로.
     * 빈 칸도 양식에 서식이 있으면 같이 바뀐다(나중에 사람이 금액을 넣어도 소수점이 보이게). 이미 소수점이 있는 서식은 그대로.
     */
    public static function decimalAmounts(Worksheet $ws): void
    {
        foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
            $fmt = $ws->getStyle($coord)->getNumberFormat();
            $code = (string) $fmt->getFormatCode();
            if (! str_contains($code, '#,##0') || str_contains($code, '#,##0.')) {
                continue;
            }
            $fmt->setFormatCode(str_replace('#,##0', '#,##0.00', $code));
        }
    }

    /**
     * 합계 3칸(SUB TOTAL · TOTAL · BALANCE MONEY)을 값 → 수식으로 (jin 2026-11-26 «합계도 소수점이 그대로 나와야»).
     * 은행에 맞춰 차량 줄 금액을 고치면 합계가 소수점까지 따라 바뀐다.
     *   SUB TOTAL = SUM(차량 줄 E:F) — 서류 합계식(`DocValue::documentSaleTotal` = Σ FOB + 운임)과 같은 범위다.
     *   TOTAL · BALANCE = SUB TOTAL 칸 참조.
     * 일반 PI 는 양식 SUM 범위가 행 삭제에 안 줄어 값으로 두었다(SalesInvoiceMapping docblock) — 여기선 채운 뒤 범위를 정확히 알고 쓴다.
     * 🛡️ 지금 숫자와 수식 결과가 다르면(양식이 바뀌었다는 뜻) 건드리지 않는다 — 값으로 남는 편이 틀린 수식보다 낫다.
     */
    public static function totalsAsFormulas(Worksheet $ws): void
    {
        $header = $sub = null;
        $rows = [];
        for ($r = 1; $r <= min(200, $ws->getHighestRow()); $r++) {
            $label = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $ws->getCell('C'.$r)->getValue())));
            if ($header === null && strtoupper(trim(preg_replace('/\s+/', ' ', (string) $ws->getCell('E'.$r)->getValue()))) === 'FOB PRICE') {
                $header = $r;
            } elseif ($header !== null && $sub === null && $label === 'SUB TOTAL') {
                $sub = $r;
            } elseif ($sub !== null && in_array($label, ['TOTAL', 'BALANCE MONEY'], true)) {
                $rows[] = $r;
            }
        }
        if ($header === null || $sub === null || $sub <= $header + 1) {
            return;
        }
        $first = $header + 1;
        $last = $first;
        $sum = 0.0;
        for ($r = $first; $r < $sub; $r++) {
            $e = $ws->getCell('E'.$r)->getValue();
            $f = $ws->getCell('F'.$r)->getValue();
            if (is_numeric($e) || is_numeric($f)) {
                $last = $r;
                $sum += (float) $e + (float) $f;
            }
        }
        if (abs($sum - (float) $ws->getCell('E'.$sub)->getValue()) > 0.005) {
            return;   // 양식이 예상과 다르다 — 값 그대로
        }
        $ws->setCellValue('E'.$sub, "=SUM(E{$first}:F{$last})");
        foreach ($rows as $r) {
            $ws->setCellValue('E'.$r, '=E'.$sub);
        }
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
                if ($rec->sent_bundle_path && ! BankRemittance::where('sent_bundle_path', $rec->sent_bundle_path)->whereKeyNot($rec->id)->exists()) {
                    $paths[] = $rec->sent_bundle_path;   // 합친 PDF 는 같이 보낸 다른 건이 남아 있으면 둔다
                }
                $rec->delete();
                DB::afterCommit(fn () => Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($paths));
            }
        });
    }

    /** 이 기록을 만질 수 있나 — 재무·관리 이상이고, 이어진 차를 전부 볼 수 있어야 한다(파일 내려받기와 같은 규칙). */
    public static function canTouch(?User $user, BankRemittance $rec): bool
    {
        return self::canUse($user) && $rec->vehicles->isNotEmpty()
            && $rec->vehicles->every(fn (Vehicle $v) => $user->canScopeVehicle($v));
    }

    /**
     * 아직 은행으로 안 보낸 기록 — 모아 보내기 목록(jin 2026-11-26 «한 번에 모아서 보낸다», 며칠 치가 섞여도 된다).
     * 오래된 것부터(은행에 보낼 순서 = 만든 순서).
     *
     * @return Collection<int, BankRemittance>
     */
    public static function pending(?User $user): Collection
    {
        if (! self::canUse($user)) {
            return collect();
        }

        return BankRemittance::with(['vehicles', 'creator:id,name'])
            ->whereNull('mailed_at')->orderBy('id')->get()
            ->filter(fn (BankRemittance $r) => self::canTouch($user, $r))->values();
    }

    /**
     * 은행용 Proforma 수정본 — 엑셀로 받아 고친 파일로 바꿔 넣는다. 이후 보내기·내려받기는 이 파일이다.
     * 옛 파일은 지운다(은행으로 가는 것은 하나뿐이어야 한다). 차마다 감사로그.
     */
    public static function replaceInvoice(BankRemittance $rec, User $user, UploadedFile $file): void
    {
        $rec->loadMissing('vehicles');
        abort_unless(self::canTouch($user, $rec), 403);
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::INVOICE_ACCEPT, true)) {
            throw new \DomainException('ext');
        }
        $disk = config('filesystems.vehicle_docs_disk');
        $path = $file->storeAs('bank-remittances/'.now()->format('Ym'), Str::uuid().'.'.$ext, $disk);
        if (! $path || ! Storage::disk($disk)->exists($path)) {   // §8 #47
            throw new \RuntimeException('Proforma 수정본 저장 실패');
        }
        $old = ['path' => $rec->invoice_path, 'name' => $rec->invoice_name];
        $name = mb_substr($file->getClientOriginalName(), 0, 200);

        DB::transaction(function () use ($rec, $user, $path, $name, $old) {
            $rec->update(['invoice_path' => $path, 'invoice_name' => $name, 'invoice_revised_at' => now(), 'invoice_revised_by' => $user->id]);
            foreach ($rec->vehicles as $v) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $v->id,
                    'action' => 'bank_invoice_replaced',
                    'column_name' => 'bank_remittance',
                    'old_value' => $old['name'],
                    'new_value' => $name,
                    'ip_address' => request()?->ip(),
                ]);
            }
        });
        if ($old['path'] && $old['path'] !== $path) {
            Storage::disk($disk)->delete($old['path']);
        }
    }

    /**
     * 은행 담당자에게 전신문 + 은행용 PI 첨부 메일(1건). 실패면 예외(호출측 토스트). 로그는 차마다 1행.
     */
    public static function send(BankRemittance $rec, User $user, string $to, string $subject, string $body): void
    {
        self::sendBatch(collect([$rec]), $user, $to, $subject, $body);
    }

    /**
     * 메일에 실을 파일 — 여러 건이면 이름 앞에 순번(01_ · 02_)을 붙여 전신문과 Proforma 가 짝지어 보이게 한다.
     *
     * @param  Collection<int, BankRemittance>  $recs
     * @return array<int, list<array{path: string, name: string}>> 기록 id => 파일들
     */
    public static function batchFiles(Collection $recs): array
    {
        $many = $recs->count() > 1;
        $out = [];
        foreach ($recs->values() as $i => $rec) {
            $no = $many ? sprintf('%02d_', $i + 1) : '';
            $files = [['path' => $rec->remittance_path, 'name' => $no.$rec->remittance_name]];
            if ($rec->invoice_path) {
                $files[] = ['path' => $rec->invoice_path, 'name' => $no.$rec->invoice_name];
            }
            $out[$rec->id] = $files;
        }

        return $out;
    }

    /**
     * 여러 건을 메일 한 통으로 (jin 2026-11-26 — 은행엔 며칠 치를 모아 한 번에 보낸다). 한 통이라 전부 가거나 전부 안 간다.
     * 실패 사유(DomainException 메시지) = not_configured · batch_count · file_missing · too_large.
     *
     * @param  Collection<int, BankRemittance>  $recs
     */
    public static function sendBatch(Collection $recs, User $user, string $to, string $subject, string $body, ?UploadedFile $merged = null): void
    {
        $company = CompanyMailConfig::active();
        if (! $company->isConfigured()) {
            throw new \DomainException('not_configured');
        }
        $recs = $recs->values();
        if ($recs->isEmpty() || $recs->count() > self::BATCH_MAX) {
            throw new \DomainException('batch_count');
        }
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        // 📎 합친 PDF 한 파일로 (jin 2026-11-27 «싼카에서 PDF 합쳐진 파일처럼») — 화면이 전신문 → Proforma 순서로 이어 붙여 올린 파일.
        //    은행에 실제로 간 파일이라 보관하고, 건마다 그 파일을 가리킨다. 메일이 실패하면 지운다.
        $bundle = null;
        if ($merged) {
            if (strtolower($merged->getClientOriginalExtension()) !== 'pdf' || ! str_starts_with((string) file_get_contents($merged->getRealPath(), false, null, 0, 5), '%PDF-')) {
                throw new \DomainException('merged_invalid');
            }
            $path = $merged->storeAs('bank-remittances/'.now()->format('Ym'), Str::uuid().'_bundle.pdf', config('filesystems.vehicle_docs_disk'));
            if (! $path || ! $disk->exists($path)) {
                throw new \RuntimeException('합친 PDF 저장 실패');
            }
            $bundle = ['path' => $path, 'name' => self::bundleName($recs->count())];
        }
        $byRec = $bundle ? $recs->mapWithKeys(fn ($r) => [$r->id => [$bundle]])->all() : self::batchFiles($recs);
        $files = $bundle ? [$bundle] : array_merge(...array_values($byRec));
        $bytes = 0;
        foreach ($files as $f) {
            if (! $disk->exists($f['path'])) {
                throw new \DomainException('file_missing');
            }
            $bytes += $disk->size($f['path']);
        }
        if ($bytes > self::BATCH_MAX_BYTES) {
            if ($bundle) {
                $disk->delete($bundle['path']);
            }
            throw new \DomainException('too_large');
        }
        $subject = trim($subject) !== '' ? trim($subject) : $company->companyLabel();
        $mailable = (new VehicleDocumentMail($subject, $body, $files))->to($to);
        $log = function (string $status, ?string $err) use ($recs, $byRec, $user, $company, $to, $subject) {
            foreach ($recs as $rec) {
                foreach ($rec->vehicles()->pluck('vehicles.id') as $vid) {
                    MailDeliveryLog::create([
                        'vehicle_id' => $vid, 'user_id' => $user->id, 'channel' => $company->channel, 'from_address' => $company->fromAddress,
                        'to_email' => $to, 'subject' => $subject, 'document_names' => array_column($byRec[$rec->id], 'name'), 'status' => $status, 'error' => $err,
                    ]);
                }
            }
        };

        try {
            $company->send($mailable);
        } catch (\Throwable $e) {
            $log('failed', mb_substr($e->getMessage(), 0, 500));
            if ($bundle) {
                $disk->delete($bundle['path']);
            }
            throw $e;
        }
        $log('sent', null);
        $now = now();
        foreach ($recs as $rec) {
            $rec->update(['mailed_to' => $to, 'mailed_at' => $now, 'sent_bundle_path' => $bundle['path'] ?? null, 'sent_bundle_name' => $bundle['name'] ?? null]);
        }
    }

    /** 합친 PDF 임시 보관 폴더(서버 로컬) — 보내면 정식 보관소로 옮기고 지운다. */
    public static function mergedTmpPath(string $token): ?string
    {
        return Str::isUuid($token) ? storage_path('app/bank-merged-tmp/'.$token.'.pdf') : null;
    }

    /** 화면이 합쳐 올린 PDF 를 임시로 받아 두고 표(token)를 돌려준다. 하루 넘은 임시 파일은 이때 치운다. */
    public static function storeMergedTmp(UploadedFile $file): string
    {
        $dir = storage_path('app/bank-merged-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach (glob($dir.'/*.pdf') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $token = (string) Str::uuid();
        $file->move($dir, $token.'.pdf');
        if (! is_file($dir.'/'.$token.'.pdf')) {
            throw new \RuntimeException('합친 PDF 임시 저장 실패');
        }

        return $token;
    }

    public static function forgetMergedTmp(string $token): void
    {
        $path = self::mergedTmpPath($token);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /** 합친 PDF 파일 이름 — 「해외송금증빙_20261127_3건.pdf」. */
    public static function bundleName(int $count): string
    {
        return '해외송금증빙_'.now()->format('Ymd').'_'.$count.'건.pdf';
    }
}
