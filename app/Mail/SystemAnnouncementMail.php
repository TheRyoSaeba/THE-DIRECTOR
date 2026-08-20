<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SystemAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        $viewData = [
            'displayName'    => $this->user->character?->display_name ?? $this->user->username,
            'subjectLine'    => $this->subjectLine,
            'bodyText'       => $this->bodyText,
            'unsubscribeUrl' => $this->safeUnsubscribeUrl(),
        ];

        // HTML + plain-text alternative. Gmail weights bulk mail with both
        // formats present significantly higher than HTML-only.
        return new Content(
            view: 'emails.announcement',
            text: 'emails.announcement-text',
            with: $viewData,
        );
    }

    /**
     * Deliverability headers. List-Unsubscribe is the single biggest win:
     * Gmail explicitly downranks bulk mail without it (RFC 8058).
     */
    public function headers(): Headers
    {
        try {
            $unsubscribeUrl = $this->safeUnsubscribeUrl();

            if (!$unsubscribeUrl) {
                return new Headers();
            }

            return new Headers(
                text: [
                    'List-Unsubscribe'      => "<{$unsubscribeUrl}>",
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    'Precedence'            => 'bulk',
                    'Auto-Submitted'        => 'auto-generated',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[SystemAnnouncementMail] headers build failed', [
                'user_id' => $this->user->id,
                'error'   => $e->getMessage(),
            ]);
            return new Headers();
        }
    }

    private function safeUnsubscribeUrl(): ?string
    {
        try {
            return URL::signedRoute('unsubscribe', ['user' => $this->user->id]);
        } catch (\Throwable $e) {
            Log::warning('[SystemAnnouncementMail] signed URL failed', [
                'user_id' => $this->user->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }
}
