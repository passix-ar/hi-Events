<?php

namespace HiEvents\Mail\User;

use HiEvents\Helper\Url;
use HiEvents\Mail\BaseMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * @uses /backend/resources/views/emails/auth/forgot-password.blade.php
 */
class ForgotPassword extends BaseMail
{
    private string $userEmail;

    private string $token;

    public function __construct(string $userEmail, string $token)
    {
        parent::__construct();

        $this->userEmail = $userEmail;
        $this->token = $token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Password reset'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.auth.forgot-password',
            with: [
                'userEmail' => $this->userEmail,
                'link' => sprintf(Url::getFrontEndUrlFromConfig(Url::RESET_PASSWORD), $this->token),
            ]
        );
    }
}
