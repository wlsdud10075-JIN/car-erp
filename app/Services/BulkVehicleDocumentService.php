<?php

namespace App\Services;

use App\Exceptions\FileStoreFailedException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 서류 일괄 업로드 (jin 2026-09-04) — 기획 = `docs/design/bulk-document-upload.md`.
 *
 * 🔑 **서류는 두 부류다.**
 *   - 개별형(말소증)                = 차량마다 **다른 파일**  (3단계, 미구현)
 *   - 공유형(수출신고서·체크빌·B/L) = **하나의 파일**이 여러 대에
 *
 * 공유형이 성립하는 이유 = 그 서류들이 애초에 **묶음 문서**이기 때문이다.
 * 실측(heymanerp 2026-09-04): `bl_number` 42종이 평균 3.3대·최대 25대를 덮고,
 * `export_declaration_number` 69종이 평균 2.2대를 덮는다.
 *
 * ⚠️ 그래도 **예외가 실재한다** — 선적요청 배치 44개 중 3개는 배치 안에서 번호가 갈리고,
 *    한 배치는 차량 4대에 신고번호가 4종이다. 거기 면장 1장을 붙이면 3대가 **틀린 세관 서류**를 갖는다.
 *    ⇒ `preview()` 가 번호 분포(섞임)와 선택 밖 잔여(빠짐)를 돌려주고, 화면이 사람에게 확인시킨다.
 *
 * 대상은 사람이 체크박스로 고른 것(`shipDocIds`)이다. ID 자체는 사람의 선택이라 그대로 쓰되,
 * **차량별 `canScopeVehicle` 재인가는 반드시** 한다(SKILLS §8 #26).
 */
class BulkVehicleDocumentService
{
    public const MODE_SHARED = 'shared';

    public const MODE_INDIVIDUAL = 'individual';

    /**
     * 일괄 업로드 가능한 서류.
     *   column    저장 컬럼 (단건 화면 fileFields 와 같은 컬럼)
     *   group_by  묶음 번호 컬럼. **null 이면 구성 검사를 하지 않는다.**
     *   ability   버튼·실행 권한. 단건 화면과 같게 맞춘다 —
     *             "한 대씩은 못 올리는데 일괄로는 올려진다" 는 구멍을 만들지 않기 위함(jin).
     *
     * 🚫 체크빌에 group_by 를 넣지 말 것 — 체크빌은 **B/L 발행 전단계** 확인용이라
     *    그 시점에 bl_number 가 아직 없다. 선박명·컨테이너 검색으로 사람이 이미 묶는다(jin 2026-09-04).
     */
    public const DOCUMENTS = [
        'checkbill' => [
            'mode' => self::MODE_SHARED,
            'column' => 'checkbill_document',
            'group_by' => null,
            'ability' => 'canAccessClearance',
        ],
        'export_declaration' => [
            'mode' => self::MODE_SHARED,
            'column' => 'export_declaration_document',
            'group_by' => 'export_declaration_number',
            'ability' => 'canAccessClearance',
        ],
    ];

    /** @return array{mode:string,column:string,group_by:?string,ability:string} */
    public static function config(string $type): array
    {
        if (! isset(self::DOCUMENTS[$type])) {
            throw new InvalidArgumentException("일괄 업로드 대상이 아닌 서류: {$type}");
        }

        return self::DOCUMENTS[$type];
    }

    /** 그 사용자가 올릴 수 있는 서류 종류. 화면 드롭다운은 이 목록만 그린다. */
    public static function allowedFor(User $user): array
    {
        return array_keys(array_filter(
            self::DOCUMENTS,
            fn (array $cfg) => (bool) $user->{$cfg['ability']}()
        ));
    }

    /** 실제로 값이 들어 있는 번호 종류 수 — 2 이상이면 「섞임」 확인 대상. */
    public static function distinctGroupCount(array $breakdown): int
    {
        return count(array_filter(
            $breakdown,
            fn ($cnt, $group) => trim((string) $group) !== '' && $cnt > 0,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * 미리보기 — 화면이 그리는 것과 apply() 가 쓰는 판정이 **같은 함수**에서 나온다(SKILLS §8 #67).
     *
     * @return array{targets:array,no_scope:array,breakdown:array,outside:array}
     */
    public function preview(array $vehicleIds, string $type, User $by): array
    {
        $cfg = self::config($type);
        $this->authorize($by, $cfg, $type);

        $ids = self::normalizeIds($vehicleIds);
        $vehicles = $ids === []
            ? collect()
            : Vehicle::whereIn('id', $ids)->orderBy('vehicle_number')->get();

        $targets = [];
        $noScope = [];
        foreach ($vehicles as $v) {
            if (! $by->canScopeVehicle($v)) {
                $noScope[] = $v->vehicle_number;

                continue;
            }
            $targets[] = self::row($v, $cfg);
        }

        $breakdown = [];
        $outside = [];
        if ($cfg['group_by'] !== null) {
            foreach ($targets as $t) {
                $breakdown[$t['group']] = ($breakdown[$t['group']] ?? 0) + 1;
            }
            arsort($breakdown);

            // 「빠짐」 — 같은 번호인데 선택에 안 들어온 차. 25대짜리 묶음에서 20대만 골라도
            //   나머지 5대가 서류 없이 남지 않게 한다. 값이 빈 번호는 묶음이 아니므로 제외.
            $groups = array_values(array_filter(array_keys($breakdown), fn ($g) => trim((string) $g) !== ''));
            if ($groups !== []) {
                $rows = Vehicle::whereIn($cfg['group_by'], $groups)
                    ->whereNotIn('id', array_column($targets, 'id'))
                    ->orderBy('vehicle_number')->get();
                foreach ($rows as $v) {
                    if ($by->canScopeVehicle($v)) {
                        $outside[] = self::row($v, $cfg);
                    }
                }
            }
        }

        return ['targets' => $targets, 'no_scope' => $noScope, 'breakdown' => $breakdown, 'outside' => $outside];
    }

    /**
     * 공유형 적용 — 파일 **1개**를 대상 차량 각각에 저장한다.
     *
     * 🚨 **파일 내용을 한 번만 읽는다.** Livewire 임시파일의 storeAs() 는 대상 디스크가 임시 디스크와
     *    같으면 move() 라 **첫 저장에서 원본이 사라진다**(다르면 put). 디스크 설정에 따라 동작이
     *    갈리므로 store() 를 N번 부르지 않는다. 더해서 그 메서드는 실패해도 경로를 그대로 돌려주므로
     *    (put 반환값을 버린다) 여기서 직접 put 하고 **반환값과 존재를 둘 다** 확인한다(SKILLS §8 #47).
     *
     * 🚫 차량마다 **다른 경로**에 저장한다. 한 경로를 공유하면 한 대의 서류를 교체·삭제할 때
     *    나머지 차량의 서류가 함께 깨진다.
     *
     * @return array{applied:int,skipped:array}
     */
    public function applyShared(
        array $vehicleIds,
        string $type,
        UploadedFile $file,
        User $by,
        bool $replaceExisting,
        string $reason,
    ): array {
        $cfg = self::config($type);
        $this->authorize($by, $cfg, $type);
        if ($cfg['mode'] !== self::MODE_SHARED) {
            throw new InvalidArgumentException("공유형이 아닌 서류: {$type}");
        }

        $ids = self::normalizeIds($vehicleIds);
        if ($ids === []) {
            throw new InvalidArgumentException('대상 차량이 없습니다.');
        }

        $disk = (string) config('filesystems.vehicle_docs_disk');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');
        // Livewire 임시파일은 `get()` 이 임시 디스크에서 직접 읽는다(임시 디스크가 s3 여도 동작).
        //   테스트의 평범한 UploadedFile 에는 그 메서드가 없어 Symfony 의 getContent() 로 떨어진다.
        $content = method_exists($file, 'get') ? $file->get() : $file->getContent();

        $applied = 0;
        $skipped = [];

        foreach (Vehicle::whereIn('id', $ids)->orderBy('vehicle_number')->get() as $vehicle) {
            $stored = null;
            try {
                if (! $by->canScopeVehicle($vehicle)) {
                    $skipped[] = self::skip($vehicle, 'no_scope');

                    continue;
                }
                $old = $vehicle->{$cfg['column']};
                if (filled($old) && ! $replaceExisting) {
                    $skipped[] = self::skip($vehicle, 'has_file');

                    continue;
                }

                $path = "vehicles/{$vehicle->id}/".Str::random(40).'.'.$ext;
                if (! Storage::disk($disk)->put($path, $content) || ! Storage::disk($disk)->exists($path)) {
                    throw new FileStoreFailedException($cfg['column']);
                }
                $stored = $path;

                // 차량별 트랜잭션 — 한 대가 걸려도 나머지가 통째로 롤백되지 않는다
                //   (선적일 일괄은 chunkById 전체를 감싸고 있어 그 함정이 있다).
                DB::transaction(function () use ($vehicle, $cfg, $path, $by, $reason, $type) {
                    $vehicle->update([$cfg['column'] => $path]);
                    AuditLog::create([
                        'user_id' => $by->id,
                        'auditable_type' => Vehicle::class,
                        'auditable_id' => $vehicle->id,
                        'action' => 'bulk_document_uploaded',
                        'column_name' => $cfg['column'],
                        'old_value' => $type,
                        'new_value' => $reason,
                        'ip_address' => request()?->ip(),
                    ]);
                });

                // 옛 파일 삭제는 **저장·확정이 끝난 뒤에만**. 먼저 지우면 실패 시 둘 다 잃는다(SKILLS §8 #47).
                if (filled($old) && $old !== $path) {
                    Storage::disk($disk)->delete($old);
                }
                $applied++;
            } catch (\Throwable $e) {
                // DB 확정에 실패했으면 방금 올린 파일은 고아다 — 지운다.
                if ($stored !== null) {
                    Storage::disk($disk)->delete($stored);
                }
                $skipped[] = self::skip($vehicle, 'error', $e->getMessage());
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function authorize(User $by, array $cfg, string $type): void
    {
        if (! $by->{$cfg['ability']}()) {
            throw new AuthorizationException("서류 일괄 업로드 권한 없음: {$type}");
        }
    }

    private static function row(Vehicle $v, array $cfg): array
    {
        return [
            'id' => $v->id,
            'number' => (string) $v->vehicle_number,
            'group' => $cfg['group_by'] !== null ? trim((string) $v->{$cfg['group_by']}) : '',
            'has_file' => filled($v->{$cfg['column']}),
        ];
    }

    private static function skip(Vehicle $v, string $reason, ?string $message = null): array
    {
        return array_filter([
            'id' => $v->id,
            'number' => (string) $v->vehicle_number,
            'reason' => $reason,
            'message' => $message,
        ], fn ($x) => $x !== null);
    }

    /** @return array<int> */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
