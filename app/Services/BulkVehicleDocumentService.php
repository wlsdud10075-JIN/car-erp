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
        /**
         * 🚨 B/L 은 **연쇄가 가장 길다.** 붙는 순간 v4 cascade #1 로 거래완료가 되고,
         *    `Vehicle::saving` 이 출고일을 선적일로 자동으로 채운다 → 재고 이탈 · 선적전/후 미수 재분류.
         *    그래서 화면이 「이 작업으로 N대가 거래완료가 됩니다」를 미리 말한다.
         * 🔒 유일하게 **게이트가 있는 서류** — 반입지 선행(H3) + 미수 100% 완납(G1).
         *    판정은 모델의 `blUploadBlocker()` 단일 출처를 쓴다(저장 훅과 사본이 아니다).
         */
        'bl' => [
            'mode' => self::MODE_SHARED,
            'column' => 'bl_document',
            'group_by' => 'bl_number',
            'ability' => 'canAccessClearance',
            'blocker' => 'blUploadBlocker',
            'completes_deal' => true,
        ],
        // 개별형 — 말소증은 **차량 1대 = 1장**이라 행마다 다른 파일을 받는다.
        //   권한이 다르다: 영업도 올릴 수 있다(단건 화면과 동일).
        'deregistration' => [
            'mode' => self::MODE_INDIVIDUAL,
            'column' => 'deregistration_document',
            'group_by' => null,
            'ability' => 'canHandleDeregistration',
        ],
    ];

    /**
     * 개별형 한 번에 받는 최대 대수.
     * 근거는 **파일 개수**다 — 브라우저 임시 업로드 × N + S3 왕복 × N + 사람이 N개를 정확히 배정해야 한다.
     * 공유형은 파일이 1개라 이 상한이 없다(묶음 크기가 곧 대상, 실측 B/L 최대 25대).
     */
    public const INDIVIDUAL_MAX = 30;

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

        $ext = self::extOf($file);
        $content = self::contentOf($file);

        $applied = 0;
        $skipped = [];

        foreach (Vehicle::whereIn('id', $ids)->orderBy('vehicle_number')->get() as $vehicle) {
            $result = $this->writeOne($vehicle, $cfg, $type, $content, $ext, $by, $replaceExisting, $reason);
            if ($result === null) {
                $applied++;
            } else {
                $skipped[] = $result;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * 한 대에 쓰기 — 공유형·개별형이 **같은 함수**를 쓴다(스코프·교체·게이트·감사·롤백 규칙이 갈리지 않게).
     *
     * @return array|null 성공이면 null, 아니면 skip 항목
     */
    private function writeOne(
        Vehicle $vehicle,
        array $cfg,
        string $type,
        string $content,
        string $ext,
        User $by,
        bool $replaceExisting,
        string $reason,
    ): ?array {
        $disk = (string) config('filesystems.vehicle_docs_disk');
        $stored = null;
        try {
            if (! $by->canScopeVehicle($vehicle)) {
                return self::skip($vehicle, 'no_scope');
            }
            // 게이트(B/L 만) — 미리보기가 회색으로 보여준 것과 **같은 판정**이다(SKILLS §8 #67).
            if (($blocker = self::blockerFor($vehicle, $cfg)) !== null) {
                return self::skip($vehicle, $blocker);
            }
            $old = $vehicle->{$cfg['column']};
            if (filled($old) && ! $replaceExisting) {
                return self::skip($vehicle, 'has_file');
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

            return null;
        } catch (\Throwable $e) {
            // DB 확정에 실패했으면 방금 올린 파일은 고아다 — 지운다.
            if ($stored !== null) {
                Storage::disk($disk)->delete($stored);
            }

            return self::skip($vehicle, 'error', $e->getMessage());
        }
    }

    /** 그 차에 이 서류를 붙일 수 없는 사유(모델 판정 위임). 붙일 수 있으면 null. */
    private static function blockerFor(Vehicle $vehicle, array $cfg): ?string
    {
        $method = $cfg['blocker'] ?? null;

        return $method !== null ? $vehicle->{$method}() : null;
    }

    /**
     * Livewire 임시파일은 `get()` 이 임시 디스크에서 직접 읽는다(임시 디스크가 s3 여도 동작).
     * 테스트의 평범한 `UploadedFile` 에는 그 메서드가 없어 Symfony 의 `getContent()` 로 떨어진다.
     *
     * 🚨 **`store()` 를 N번 부르지 않는 이유** = Livewire `storeAs()` 는 대상 디스크가 임시 디스크와
     *    같으면 `move()` 라 첫 저장에서 원본이 사라진다. 더해서 `put` 반환값을 버리고 경로를 그대로
     *    돌려주므로 실패를 못 잡는다(SKILLS §8 #47-B). 그래서 여기서 내용을 읽어 직접 쓴다.
     */
    private static function contentOf(UploadedFile $file): string
    {
        return method_exists($file, 'get') ? $file->get() : $file->getContent();
    }

    private static function extOf(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: 'dat');
    }

    /**
     * 개별형 적용 — 차량마다 **다른 파일**. 말소증은 1대 = 1장이라 공유가 성립하지 않는다.
     *
     * @param  array<int|string, UploadedFile|null>  $filesByVehicleId  차량 id => 그 차의 파일
     * @return array{applied:int,skipped:array}
     */
    public function applyIndividual(
        array $filesByVehicleId,
        string $type,
        User $by,
        bool $replaceExisting,
        string $reason,
    ): array {
        $cfg = self::config($type);
        $this->authorize($by, $cfg, $type);
        if ($cfg['mode'] !== self::MODE_INDIVIDUAL) {
            throw new InvalidArgumentException("개별형이 아닌 서류: {$type}");
        }

        // 파일이 실제로 올라온 행만 대상 — 빈 칸은 「안 건드림」이지 지우기가 아니다.
        $files = [];
        foreach ($filesByVehicleId as $id => $file) {
            if ($file instanceof UploadedFile && (int) $id > 0) {
                $files[(int) $id] = $file;
            }
        }
        if ($files === []) {
            throw new InvalidArgumentException('올릴 파일이 없습니다.');
        }
        if (count($files) > self::INDIVIDUAL_MAX) {
            throw new InvalidArgumentException('한 번에 '.self::INDIVIDUAL_MAX.'대까지만 올릴 수 있습니다.');
        }

        $applied = 0;
        $skipped = [];
        foreach (Vehicle::whereIn('id', array_keys($files))->orderBy('vehicle_number')->get() as $vehicle) {
            $file = $files[$vehicle->id];
            $result = $this->writeOne(
                $vehicle, $cfg, $type, self::contentOf($file), self::extOf($file), $by, $replaceExisting, $reason
            );
            if ($result === null) {
                $applied++;
            } else {
                $skipped[] = $result;
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
            'blocked' => self::blockerFor($v, $cfg),
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
