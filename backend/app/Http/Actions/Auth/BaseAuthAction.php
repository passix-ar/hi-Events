<?php

namespace HiEvents\Http\Actions\Auth;

use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Auth\AuthenticatedResponseResource;
use HiEvents\Services\Application\Handlers\Auth\DTO\AuthenticatedResponseDTO;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

abstract class BaseAuthAction extends BaseAction
{
    protected function getAuthCookie(string $token): SymfonyCookie
    {
        return Cookie::make(
            name: 'token',
            value: $token,
            secure: true,
            sameSite: 'None',
        );
    }

    protected function addTokenToResponse(JsonResponse|Response $response, ?string $token): JsonResponse
    {
        if (!$token) {
            return $response;
        }

        $response = $response->withCookie($this->getAuthCookie($token));

        $response->header('X-Auth-Token', $token);

        return $response;
    }

    protected function respondWithToken(?string $token, Collection $accounts): JsonResponse
    {
        return $this->buildAuthenticatedResponse($token, $accounts, $this->getAuthenticatedUser());
    }

    /**
     * Builds the response from the login result itself.
     *
     * Unlike respondWithToken(), this does not read the authenticated user from the guard,
     * so it works for sign-in methods that never populate it — such as Google, where no
     * password is ever presented to the guard.
     */
    protected function respondWithLoginResponse(LoginResponse $loginResponse): JsonResponse
    {
        return $this->buildAuthenticatedResponse(
            token: $loginResponse->token,
            accounts: $loginResponse->accounts,
            user: $loginResponse->user,
        );
    }

    private function buildAuthenticatedResponse(
        ?string          $token,
        Collection       $accounts,
        UserDomainObject $user,
    ): JsonResponse
    {
        return $this->addTokenToResponse(
            response: $this->jsonResponse(new AuthenticatedResponseResource(new AuthenticatedResponseDTO(
                token: $token,
                expiresIn: auth()->factory()->getTTL() * 60,
                accounts: $accounts,
                user: $user,
            ))),
            token: $token
        );
    }
}
