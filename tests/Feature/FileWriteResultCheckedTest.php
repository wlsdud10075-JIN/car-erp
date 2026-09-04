<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 파일 쓰기 반환값을 검사하는지 **정적으로** 확인한다 (2026-08-10).
 *
 * 배경: `config/filesystems.php` 의 모든 디스크가 `'throw' => false` 라,
 * `store()`·`storeAs()`·`put()`·`copy()`·`writeStream()` 은 실패해도 **예외 없이 `false`** 를 리턴한다.
 * 반환값을 안 보면 그대로 DB 만 확정되고, 심한 경우 **멀쩡하던 옛 파일까지 삭제**된다.
 *
 * 실제 사고: 연동 B 첨부가 "사진 행 15건 / S3 객체 0건" 으로 쌓였다(운영 heymanerp).
 * 같은 형태가 서류 교체·도장·전자서명·DB 백업 등 8곳에 더 있었다.
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — `Storage::fake()` 는 로컬 드라이버라 쓰기가 늘 성공한다.
 *    그래서 "코드가 반환값을 보고 있는지"를 소스에서 직접 확인한다.
 */
class FileWriteResultCheckedTest extends TestCase
{
    /**
     * 파일:호출 → 그 호출의 결과가 반드시 검사돼야 하는 지점들.
     * 값은 "그 파일 안에 반드시 존재해야 하는 가드 문자열".
     */
    private const GUARDED = [
        // 연동 B 첨부 — 실제로 터진 곳
        'app/Http/Controllers/Webhook/PurchaseSyncController.php' => [
            '$ok = $sameDisk',
            'if (! $ok || ! $targetDisk->exists($target))',
        ],
        // 차량 서류·사진·입금증빙 — 실패 시 옛 파일까지 삭제되던 곳
        'resources/views/livewire/erp/vehicles/index.blade.php' => [
            "throw new \\App\\Exceptions\\FileStoreFailedException(\$f['col'])",
            "FileStoreFailedException('vehicle_photo')",
            "FileStoreFailedException('ship_photo')",
            "FileStoreFailedException('payment_proof')",
            // 2026-09-04 보강 — 반환값만 보면 **화면 업로드에선 영영 안 걸린다**(SKILLS §8 #47-B).
            //   Livewire TemporaryUploadedFile::storeAs() 가 내부 put/move 반환값을 버리고 경로를
            //   무조건 돌려주기 때문. 그래서 exists() 까지 봤는지 함께 강제한다.
            "! Storage::disk(config('filesystems.vehicle_docs_disk'))->exists(\$newPath)",
            "! Storage::disk(config('filesystems.vehicle_docs_disk'))->exists(\$photoPath)",
            "! Storage::disk(config('filesystems.vehicle_docs_disk'))->exists(\$path)",
        ],
        // 도장 — 옛 도장을 먼저 지우던 곳
        'resources/views/livewire/admin/settings.blade.php' => [
            'exists($path)) {',
            'if ($old && $old !== $path) {',
        ],
        // 전자서명 — 파일 없는 "서명완료" 가 확정되던 곳
        'app/Http/Controllers/SignController.php' => [
            '$stored = $disk->put($sigPath, $png) && $disk->put($signedPath, $signedPdf);',
            'if (! $stored || ! $disk->exists($signedPath))',
        ],
        // 서명 세션 — 저장 실패 후 기존 링크를 폐기하던 곳
        'app/Services/Documents/SigningSessionService.php' => [
            '$stored = $disk->put($xlsxPath, $xlsxBytes)',
            'if (! $stored || ! $disk->exists($xlsxPath))',
        ],
        // DB 백업 — 실패인데 "✓ 원격 업로드" 를 찍던 곳
        'app/Console/Commands/BackupDatabase.php' => [
            'if (! Storage::disk($disk)->put($remotePath',
        ],
    ];

    #[DataProvider('guardedFiles')]
    public function test_file_write_results_are_checked(string $file, string $needle): void
    {
        $path = base_path($file);
        $this->assertFileExists($path, "가드 대상 파일이 사라졌다: {$file}");

        $source = file_get_contents($path);
        $this->assertStringContainsString($needle, $source,
            "{$file} 에서 파일 쓰기 반환값 검사가 사라졌다.\n".
            "실패해도 DB 만 확정되는 상태로 되돌아간다(옛 파일까지 지워질 수 있다).\n".
            "찾던 것: {$needle}");
    }

    public static function guardedFiles(): array
    {
        $out = [];
        foreach (self::GUARDED as $file => $needles) {
            foreach ($needles as $i => $needle) {
                $out[$file.' #'.($i + 1)] = [$file, $needle];
            }
        }

        return $out;
    }

    /** 도장은 **저장이 삭제보다 먼저**여야 한다 — 순서가 뒤집히면 실패 시 도장이 0개가 된다. */
    public function test_stamp_upload_stores_before_deleting_old(): void
    {
        $source = file_get_contents(base_path('resources/views/livewire/admin/settings.blade.php'));
        $fn = substr($source, strpos($source, 'private function storeStamp'), 1800);

        $storePos = strpos($fn, '$file->storeAs(');
        $deletePos = strpos($fn, '$disk->delete($old)');

        $this->assertNotFalse($storePos, 'storeStamp 에서 storeAs 를 못 찾았다');
        $this->assertNotFalse($deletePos, 'storeStamp 에서 옛 도장 삭제를 못 찾았다');
        $this->assertLessThan($deletePos, $storePos,
            '도장은 새 파일을 저장한 뒤에 옛 파일을 지워야 한다 — 순서가 뒤집히면 업로드 실패 시 도장이 사라진다');
    }
}
