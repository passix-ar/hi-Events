<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Auth\Social;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Auth\SocialAuthNonceService;
use Illuminate\Http\JsonResponse;

/**
 * Hands the browser a single-use nonce to pass on to the identity provider.
 *
 * Public by design: the value is meaningless on its own and only becomes proof of a
 * fresh sign-in once it comes back inside a token Google signed.
 */
class GetSocialAuthNonceAction extends BaseAction
{
    public function __construct(private readonly SocialAuthNonceService $nonceService)
    {
    }

    public function __invoke(): JsonResponse
    {
        return $this->jsonResponse(['nonce' => $this->nonceService->issue()]);
    }
}
