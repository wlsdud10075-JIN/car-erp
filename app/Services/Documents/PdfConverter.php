<?php

namespace App\Services\Documents;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;

/**
 * DocumentFiller가 만든 xlsx(Spreadsheet)를 LibreOffice headless로 PDF 변환한다.
 *
 * 검증된 레시피 (2026-07-10 로컬 실증, 통관SET 수식 cascade까지 정상):
 *   soffice.com --headless -env:UserInstallation=file:///<프로파일>
 *     --convert-to pdf --outdir <out> <xlsx>
 * 함정 3개:
 *   ① soffice.com(콘솔용) — .exe는 GUI라 스크립트 부적합.
 *   ② outdir/입력은 Windows 절대경로(포워드슬래시) — POSIX(/tmp)면 soffice가 C:\tmp로 오해.
 *   ③ 강제 재계산 프로파일(registrymodifications.xcu, OOXMLRecalcMode=0) — 없으면 PhpSpreadsheet가
 *      preCalc=false로 남긴 캐시값(SUB TOTAL=0 등)이 그대로 찍힘.
 */
class PdfConverter
{
    /** 로드 시 전 수식 강제 재계산(0=Always). OOXML(xlsx)·ODF 둘 다. */
    private const RECALC_XCU = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
 <item oor:path="/org.openoffice.Office.Calc/Formula/Load"><prop oor:name="OOXMLRecalcMode" oor:op="fuse"><value>0</value></prop></item>
 <item oor:path="/org.openoffice.Office.Calc/Formula/Load"><prop oor:name="ODFRecalcMode" oor:op="fuse"><value>0</value></prop></item>
</oor:items>
XML;

    /**
     * Spreadsheet → PDF 바이트. 임시 작업폴더는 항상 정리(finally).
     *
     * @throws \RuntimeException 변환 실패(soffice 미설치·타임아웃·PDF 미생성)
     */
    public function fromSpreadsheet(Spreadsheet $spreadsheet): string
    {
        $workDir = $this->makeWorkDir();
        $xlsxPath = $workDir.'/doc.xlsx';

        try {
            // xlsx 저장 — preCalc=false 유지(수식 재계산은 soffice에 위임)
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($xlsxPath);

            // 1차: 공유(웜) 프로파일 — 재초기화 비용이 없어 ~2초 빠름(실측 4.5s→2.5s).
            [$pdf, $err] = $this->convert($xlsxPath, $workDir, $this->sharedProfile());
            if ($pdf !== null) {
                return $pdf;
            }

            // 2차 폴백: 격리 프로파일 — 동시 변환으로 공유 프로파일이 락 걸렸을 때 안전망.
            $isolated = $workDir.'/profile';
            $this->seedProfile($isolated);
            [$pdf, $err] = $this->convert($xlsxPath, $workDir, $isolated);
            if ($pdf !== null) {
                return $pdf;
            }

            throw new \RuntimeException('LibreOffice PDF 변환 실패: '.$err);
        } finally {
            $this->deleteDir($workDir);
        }
    }

    /**
     * soffice 1회 변환. 성공 시 [pdf바이트, null], 실패 시 [null, 에러문자열].
     *
     * @return array{0: ?string, 1: string}
     */
    private function convert(string $xlsxPath, string $workDir, string $profileDir): array
    {
        $pdfPath = $workDir.'/doc.pdf';
        @unlink($pdfPath);

        $process = new Process([
            $this->binary(),
            '--headless',
            '-env:UserInstallation='.$this->fileUri($profileDir),
            '--convert-to', 'pdf',
            '--outdir', $this->fsPath($workDir),
            $this->fsPath($xlsxPath),
        ]);
        $process->setTimeout(120);
        // 웹서버(Apache/artisan/www-data) 유저는 HOME/TEMP 가 없거나 쓰기 불가라 soffice 가 프로파일·임시파일을
        // 못 써서 "Io Write Code:16" 로 실패한다. 작업폴더를 명시 HOME/TEMP 로 줘서 항상 쓰기 가능하게.
        $process->setEnv([
            'HOME' => $this->fsPath($workDir),
            'TMPDIR' => $this->fsPath($workDir),
            'TEMP' => $this->fsPath($workDir),
            'TMP' => $this->fsPath($workDir),
        ]);
        $process->run();

        if (is_file($pdfPath)) {
            return [(string) file_get_contents($pdfPath), ''];
        }

        return [null, trim($process->getErrorOutput().' '.$process->getOutput())];
    }

    /** 공유 웜 프로파일(1회 초기화 후 재사용). */
    private function sharedProfile(): string
    {
        $dir = storage_path('app/pdf-tmp/_shared_profile');
        if (! is_file($dir.'/user/registrymodifications.xcu')) {
            $this->seedProfile($dir);
        }

        return $dir;
    }

    private function seedProfile(string $dir): void
    {
        @mkdir($dir.'/user', 0777, true);
        file_put_contents($dir.'/user/registrymodifications.xcu', self::RECALC_XCU);
    }

    /** soffice 실행 파일 — env(LIBREOFFICE_PATH) → 알려진 경로 → PATH fallback. */
    private function binary(): string
    {
        $configured = env('LIBREOFFICE_PATH');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        foreach ([
            'C:/Program Files/LibreOffice/program/soffice.com',
            'C:/Program Files (x86)/LibreOffice/program/soffice.com',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/opt/libreoffice/program/soffice',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'soffice';   // PATH 에 있으면 사용
    }

    /** 절대경로 → file:// URI. Windows(C:\)는 /C:/ 로 정규화. */
    private function fileUri(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:/', $p)) {
            $p = '/'.$p;   // C:/... → /C:/...
        }

        return 'file://'.$p;
    }

    /** soffice 인자용 경로(포워드슬래시). */
    private function fsPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function makeWorkDir(): string
    {
        $base = storage_path('app/pdf-tmp');
        @mkdir($base, 0777, true);
        $dir = $base.'/'.uniqid('conv_', true);
        @mkdir($dir, 0777, true);

        return $dir;
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
