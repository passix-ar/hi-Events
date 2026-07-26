<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Auth\Social;

use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialAuthDisabledException;
use HiEvents\Exceptions\SocialAuth\SocialIdentityAlreadyLinkedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\Auth\BaseAuthAction;
use HiEvents\Http\Request\Auth\Social\GoogleLoginRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Auth\SocialRegistrationRequiredResource;
use HiEvents\Services\Application\Handlers\Auth\Social\DTO\GoogleLoginDTO;
use HiEvents\Services\Application\Handlers\Auth\Social\GoogleLoginHandler;
use Illuminate\Http\JsonResponse;
use Throwable;

class GoogleLoginAction extends BaseAuthAction
{
    public function __construct(private readonly GoogleLoginHandler $googleLoginHandler)
    {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(GoogleLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->googleLoginHandler->handle(new GoogleLoginDTO(
                idToken: $request->validated('id_token'),
                accountId: $request->validated('account_id') === null
                    ? null
                    : (int)$request->validated('account_id'),
            ));
        } catch (SocialAuthDisabledException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_FORBIDDEN,
            );
        } catch (InvalidIdTokenException|UnauthorizedException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_UNAUTHORIZED,
            );
        } catch (SocialIdentityAlreadyLinkedException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_CONFLICT,
            );
        }

        if ($result->requiresRegistration()) {
            // Deliberately unwrapped, to match the authenticated response this same
            // endpoint returns on its other branch.
            return $this->jsonResponse(new SocialRegistrationRequiredResource($result));
        }

        return $this->respondWithLoginResponse($result->loginResponse);
    }
}
