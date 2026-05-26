<?php

namespace HiEvents\Http\Actions\Accounts\MercadoPago;

use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\MercadoPagoOAuthCallbackHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Psr\Log\LoggerInterface;
use Throwable;

class MercadoPagoOAuthCallbackAction extends BaseAction
{
    public function __construct(
        private readonly MercadoPagoOAuthCallbackHandler $handler,
        private readonly Redirector                      $redirector,
        private readonly LoggerInterface                 $logger,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $code  = $request->query('code');
        $state = $request->query('state');

        $frontendUrl = config('app.frontend_url', config('app.url'));

        if (!$code || !$state) {
            return $this->redirector->to($frontendUrl . '/account/payment?mp_error=invalid_callback');
        }

        try {
            $this->handler->handle($code, $state);
        } catch (MercadoPagoOAuthException $e) {
            $this->logger->error('MercadoPago OAuth callback failed', ['error' => $e->getMessage()]);
            return $this->redirector->to($frontendUrl . '/account/payment?mp_error=oauth_failed');
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error in MercadoPago OAuth callback', ['error' => $e->getMessage()]);
            return $this->redirector->to($frontendUrl . '/account/payment?mp_error=unknown');
        }

        return $this->redirector->to($frontendUrl . '/account/payment?mp_connected=1');
    }
}
