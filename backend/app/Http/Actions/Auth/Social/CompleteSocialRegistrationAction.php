<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Auth\Social;

use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialIdentityAlreadyLinkedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Http\Actions\Auth\BaseAuthAction;
use HiEvents\Http\Request\Auth\Social\CompleteSocialRegistrationRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Account\Exceptions\AccountConfigurationDoesNotExist;
use HiEvents\Services\Application\Handlers\Account\Exceptions\AccountRegistrationDisabledException;
use HiEvents\Services\Application\Handlers\Auth\Social\CompleteSocialRegistrationHandler;
use HiEvents\Services\Application\Handlers\Auth\Social\DTO\CompleteSocialRegistrationDTO;
use HiEvents\Services\Application\Locale\LocaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompleteSocialRegistrationAction extends BaseAuthAction
{
    public function __construct(
        private readonly CompleteSocialRegistrationHandler $completeRegistrationHandler,
        private readonly LocaleService                     $localeService,
    )
    {
    }

    /**
     * @throws Throwable
     * @throws ValidationException
     */
    public function __invoke(CompleteSocialRegistrationRequest $request): JsonResponse
    {
        try {
            $loginResponse = $this->completeRegistrationHandler->handle(new CompleteSocialRegistrationDTO(
                registrationToken: $request->validated('registration_token'),
                businessName: $request->validated('business_name'),
                locale: $request->has('locale')
                    ? $request->validated('locale')
                    : $this->localeService->getLocaleOrDefault($request->getPreferredLanguage()),
                timezone: $request->validated('timezone'),
                currencyCode: $request->validated('currency_code'),
                marketingOptIn: (bool)$request->validated('marketing_opt_in'),
                utmData: $request->only([
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                    'referrer_url', 'landing_page', 'gclid', 'fbclid', 'utm_raw',
                ]),
            ));
        } catch (InvalidIdTokenException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_UNAUTHORIZED,
            );
        } catch (EmailAlreadyExists|SocialIdentityAlreadyLinkedException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_CONFLICT,
            );
        } catch (AccountRegistrationDisabledException) {
            return $this->errorResponse(
                message: __('Account registration is disabled'),
                statusCode: ResponseCodes::HTTP_FORBIDDEN,
            );
        } catch (AccountConfigurationDoesNotExist $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_INTERNAL_SERVER_ERROR,
            );
        } catch (UnauthorizedException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: ResponseCodes::HTTP_UNAUTHORIZED,
            );
        }

        return $this->respondWithLoginResponse($loginResponse);
    }
}
