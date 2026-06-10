<?php

// Added by Passix: Verifies the Cloudflare Turnstile token on the public checkout (anti-bot).
namespace HiEvents\Http\Middleware;

use Closure;
use HiEvents\Services\Infrastructure\Captcha\TurnstileValidationService;
use Illuminate\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstileToken
{
    public function __construct(
        private readonly Config                     $config,
        private readonly TurnstileValidationService $turnstileValidationService,
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->config->get('services.turnstile.enabled')) {
            return $next($request);
        }

        $token = $request->input('captcha_token') ?? $request->header('CF-Turnstile-Response');

        if (!$this->turnstileValidationService->verify($token, $request->ip())) {
            return new JsonResponse([
                'message' => __('Captcha verification failed. Please try again.'),
                'errors' => [
                    'captcha' => [__('Captcha verification failed. Please try again.')],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $next($request);
    }
}
