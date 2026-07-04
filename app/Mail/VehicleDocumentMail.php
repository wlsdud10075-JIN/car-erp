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

    /** @param  array<int, array{path: string, name: string}>  $attachmentFiles  vehicle_docs_disk 경로 + 표시 파일명 */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public array $attachmentFiles = [],
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

        return collect($this->attachmentFiles)
            ->map(fn (array $f) => Attachment::fromStorageDisk($disk, $f['path'])->as($f['name']))
            ->all();
    }
}
