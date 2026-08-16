<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Exceptions\MercadoPago;

use RuntimeException;
use Throwable;

class MercadoPagoOAuthException extends RuntimeException
{
    // OAuth error codes from MercadoPago's response body. invalid_grant and
    // unauthorized_client are terminal: the refresh token is dead or the app
    // lost its grant, so retrying cannot fix it — the organizer must
    // re-authorize. local_rate_limited (429) is the only retryable one.
    private const TERMINAL_ERRORS = ['invalid_grant', 'unauthorized_client'];

    private const RETRYABLE_ERRORS = ['local_rate_limited'];

    public function __construct(
        string $message,
        private readonly ?string $mpErrorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getMpErrorCode(): ?string
    {
        return $this->mpErrorCode;
    }

    public function isTerminal(): bool
    {
        return in_array($this->mpErrorCode, self::TERMINAL_ERRORS, true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->mpErrorCode, self::RETRYABLE_ERRORS, true);
    }
}
