<?php

// Added by Passix: Cloudflare Turnstile validation (anti-bot CAPTCHA on the public checkout).
namespace HiEvents\Services\Infrastructure\Captcha;

use Illuminate\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

class TurnstileValidationService
{
    /**
     * Error codes returned by Cloudflare that indicate a problem on Cloudflare's side
     * rather than an invalid client token. We fail open on these so a Cloudflare
     * outage does not block ticket sales.
     *
     * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
     */
    private const CLOUDFLARE_SIDE_ERROR_CODES = [
        'internal-error',
        'bad-request',
    ];

    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * Verify a Turnstile token against the siteverify endpoint.
     *
     * Returns true when the token is valid. To avoid blocking sales during a
     * Cloudflare outage we "fail open" on transport errors or Cloudflare-side
     * error codes, but a genuinely invalid/expired/reused token returns false.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->post($this->config->get('services.turnstile.verify_url'), [
                'secret' => $this->config->get('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Turnstile verification request failed; failing open', [
                'message' => $exception->getMessage(),
            ]);

            return true;
        }

        $body = $response->json();

        if (($body['success'] ?? false) === true) {
            return true;
        }

        $errorCodes = $body['error-codes'] ?? [];

        if (array_intersect($errorCodes, self::CLOUDFLARE_SIDE_ERROR_CODES)) {
            $this->logger->warning('Turnstile verification errored on Cloudflare side; failing open', [
                'error_codes' => $errorCodes,
                'status' => $response->status(),
            ]);

            return true;
        }

        return false;
    }
}
