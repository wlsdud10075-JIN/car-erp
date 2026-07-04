<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 거래완료 후 바이어에게 차량 업로드 문서를 전달하는 메일.
 * 발신(from)·mailer 는 CompanyMailConfig::send() 가 회사 방식대로 설정한다.
 */
class VehicleDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{path: string, name: string}>  $storedFiles  vehicle_docs_disk 저장 파일(업로드 사진·단계 파일)
     * @param  array<int, array{data: string, name: string, mime?: string}>  $dataFiles  즉석 생성 문서(서류 탭 xlsx 등)
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public array $storedFiles = [],
        public array $dataFiles = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine !== '' ? $this->subjectLine : 'Document');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vehicle-document', with: ['bodyText' => $this->bodyText]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $disk = config('filesystems.vehicle_docs_disk');
        $out = [];

        foreach ($this->storedFiles as $f) {
            $out[] = Attachment::fromStorageDisk($disk, $f['path'])->as($f['name']);
        }
        foreach ($this->dataFiles as $f) {
            $data = $f['data'];
            $att = Attachment::fromData(fn () => $data, $f['name']);
            if (! empty($f['mime'])) {
                $att = $att->withMime($f['mime']);
            }
            $out[] = $att;
        }

        return $out;
    }
}
