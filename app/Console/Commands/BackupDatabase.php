<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup
                            {--filename= : 출력 파일명 (생략 시 DB명 + 타임스탬프)}
                            {--keep=30 : 보관 일수 (이전 백업 자동 삭제, 0=영구)}';

    protected $description = 'mysqldump으로 현재 DB를 storage/backups/db/ 에 백업. AWS 배포(큐 13) 시 cron 등록 예정.';

    public function handle(): int
    {
        $cfg = config('database.connections.'.config('database.default'));
        $database = $cfg['database'] ?? null;

        if (! $database) {
            $this->error('config/database.php에서 DB 이름을 읽지 못했습니다.');

            return self::FAILURE;
        }

        $dumpBinary = env('MYSQLDUMP_PATH')
            ?: (PHP_OS_FAMILY === 'Windows'
                ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe'
                : 'mysqldump');

        $timestamp = now()->format('Ymd_His');
        $filename = $this->option('filename') ?: "{$database}-{$timestamp}.sql";
        $backupDir = storage_path('backups/db');
        File::ensureDirectoryExists($backupDir);
        $backupPath = $backupDir.DIRECTORY_SEPARATOR.$filename;

        $cmd = [
            $dumpBinary,
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.((string) ($cfg['port'] ?? '3306')),
            '--user='.($cfg['username'] ?? 'root'),
        ];
        if (($cfg['password'] ?? '') !== '') {
            $cmd[] = '--password='.$cfg['password'];
        }
        $cmd[] = '--single-transaction';
        $cmd[] = '--quick';
        $cmd[] = '--default-character-set=utf8mb4';
        $cmd[] = $database;

        $fp = fopen($backupPath, 'w');
        if (! $fp) {
            $this->error("백업 파일 열기 실패: {$backupPath}");

            return self::FAILURE;
        }

        $this->info("백업 시작: {$backupPath}");
        $process = new Process($cmd);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use ($fp) {
            if ($type === Process::OUT) {
                fwrite($fp, $buffer);
            } else {
                $this->getOutput()->write($buffer);
            }
        });
        fclose($fp);

        if (! $process->isSuccessful()) {
            $this->error('mysqldump 실패 (exit '.$process->getExitCode().')');
            if (file_exists($backupPath) && filesize($backupPath) === 0) {
                unlink($backupPath);
            }

            return self::FAILURE;
        }

        $sizeKb = round(filesize($backupPath) / 1024, 1);
        $this->info("✓ 완료: {$backupPath} ({$sizeKb} KB)");

        $this->maybeUploadToRemote($backupPath, $filename);
        $this->cleanupOldBackups($backupDir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * DB_BACKUP_DISK(config filesystems.db_backup_disk) 설정 시 백업을 그 디스크(예: s3)에도 업로드.
     * 단일 인스턴스 유실에도 백업이 보존되도록 off-instance 보관. 실패해도 로컬 백업은 유효하므로 SUCCESS 유지.
     */
    private function maybeUploadToRemote(string $localPath, string $filename): void
    {
        $disk = (string) config('filesystems.db_backup_disk', '');
        if ($disk === '') {
            return;
        }
        try {
            Storage::disk($disk)->put('db-backups/'.$filename, file_get_contents($localPath));
            $this->info("✓ 원격 업로드: [{$disk}] db-backups/{$filename}");
        } catch (\Throwable $e) {
            // claudereview E — 무음 실패 제거. cron(03:00) 운영에선 콘솔 출력이 안 보이므로
            // Log::critical 로 남겨 알림 연동/모니터링이 잡을 수 있게 한다. (로컬 백업은 이미 성공.)
            $this->error("원격 업로드 실패 ([{$disk}]): ".$e->getMessage());
            Log::critical('DB 백업 원격 업로드 실패 — 원격 백업본 없음', [
                'disk' => $disk,
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupOldBackups(string $dir, int $keepDays): void
    {
        if ($keepDays <= 0) {
            return;
        }
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;
        foreach (File::files($dir) as $file) {
            if ($file->getMTime() < $cutoff) {
                unlink($file->getPathname());
                $deleted++;
            }
        }
        if ($deleted > 0) {
            $this->line("이전 백업 {$deleted}개 삭제 (--keep={$keepDays}일 경과)");
        }
    }
}
