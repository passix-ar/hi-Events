<?php

// Added by Passix on 2026-08-10: verificacion de solo lectura del token de un
// organizador. No escribe nada ni consume el refresh token — sirve para
// confirmar, antes y despues de una renovacion, que la cuenta puede cobrar.

namespace HiEvents\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\Generated\AccountMercadopagoPlatformDomainObjectAbstract;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use Illuminate\Console\Command;
use Throwable;

class CheckMercadoPagoTokenCommand extends Command
{
    private const ME_ENDPOINT = 'https://api.mercadopago.com/users/me';

    protected $signature = 'mercadopago:check-token
                            {--account= : Verificar solo esta cuenta (por account_id)}';

    protected $description = 'Verifica contra MercadoPago que los access token guardados siguen sirviendo (solo lectura)';

    public function handle(
        AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        Client $httpClient,
    ): int {
        $accountId = $this->option('account');

        $where = [[AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT, 'not null', null]];

        if ($accountId !== null) {
            $where[] = [AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID, '=', (int) $accountId];
        }

        $platforms = $platformRepository->findWhere($where);

        if ($platforms->isEmpty()) {
            $this->error($accountId !== null
                ? "No hay conexion de MercadoPago para la cuenta {$accountId}."
                : 'No hay ninguna conexion de MercadoPago.');

            return self::FAILURE;
        }

        $failures = 0;

        /** @var AccountMercadopagoPlatformDomainObject $platform */
        foreach ($platforms as $platform) {
            if (! $this->check($platform, $httpClient)) {
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(AccountMercadopagoPlatformDomainObject $platform, Client $httpClient): bool
    {
        $accountId = $platform->getAccountId();
        $token = $platform->getAccessToken();

        if (! $token) {
            $this->error("Cuenta {$accountId}: no hay access token guardado");

            return false;
        }

        try {
            $response = $httpClient->get(self::ME_ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true) ?: [];

            if ($status !== 200) {
                $this->error(sprintf(
                    'Cuenta %d: MercadoPago respondio %d — el token NO sirve (%s)',
                    $accountId,
                    $status,
                    $body['message'] ?? 'sin detalle',
                ));

                return false;
            }

            // Confirma que el token pertenece al vendedor que tenemos anotado: si
            // no coincide, la fila quedo apuntando a otra cuenta de MercadoPago.
            $returnedUserId = (string) ($body['id'] ?? '');
            $storedUserId = (string) $platform->getMpUserId();
            $matches = $returnedUserId === $storedUserId;

            $this->info(sprintf(
                'Cuenta %d: OK — vendedor %s (%s)%s | vence %s',
                $accountId,
                $returnedUserId,
                $body['nickname'] ?? 's/nick',
                $matches ? '' : " ⚠️ NO coincide con el guardado ({$storedUserId})",
                $platform->getTokenExpiresAt() ?? 's/fecha',
            ));

            return $matches;
        } catch (GuzzleException|Throwable $e) {
            $this->error("Cuenta {$accountId}: fallo la consulta a MercadoPago ({$e->getMessage()})");

            return false;
        }
    }
}
