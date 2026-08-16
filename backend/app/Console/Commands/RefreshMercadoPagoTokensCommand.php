<?php

// Added by Passix on 2026-08-07: MercadoPago access tokens expire after ~180 days,
// so connected organizers silently lose the ability to charge unless the token
// pair is refreshed before token_expires_at.

namespace HiEvents\Console\Commands;

use Carbon\Carbon;
use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\Generated\AccountMercadopagoPlatformDomainObjectAbstract;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

class RefreshMercadoPagoTokensCommand extends Command
{
    // MercadoPago's documented token lifetime; only used as a fallback when the
    // refresh response omits expires_in, so the row never loses its expiry date
    // and drops out of future refresh runs.
    private const DEFAULT_TOKEN_TTL_DAYS = 180;

    protected $signature = 'mercadopago:refresh-tokens
                            {--days=30 : Refresh tokens that expire within this many days}
                            {--account= : Refresh only this account_id, ignoring the --days window}
                            {--dry-run : List what would be refreshed without calling MercadoPago}';

    protected $description = 'Refresh MercadoPago OAuth tokens that are close to expiring';

    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;

    private MercadoPagoOAuthService $oauthService;

    private LoggerInterface $logger;

    /**
     * Dependencies are injected into handle() rather than the constructor:
     * artisan instantiates every command at boot to register it, and building
     * the OAuth service there resolves the Encrypter on every artisan call.
     */
    public function handle(
        AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        MercadoPagoOAuthService $oauthService,
        LoggerInterface $logger,
    ): int {
        $this->platformRepository = $platformRepository;
        $this->oauthService = $oauthService;
        $this->logger = $logger;
        $account = $this->option('account');

        // Con --account se apunta a una sola cuenta y se ignoran la ventana de
        // --days y la marca de revocada: sirve para probar la renovacion sin
        // tocar a los demas organizadores, y para recuperar a mano una cuenta
        // que quedo colgada o revocada (si el refresh sale bien, la marca se
        // limpia).
        $scope = $account !== null
            ? [[AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID, '=', (int) $account]]
            : [
                // Piso en now(): las ya vencidas no entran — necesitan
                // recuperacion manual via --account (o reconexion). Sin el piso,
                // la corrida diaria las martillaria indefinidamente.
                [
                    AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT,
                    '>=',
                    Carbon::now()->toDateTimeString(),
                ],
                [
                    AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT,
                    '<',
                    Carbon::now()->addDays((int) $this->option('days'))->toDateTimeString(),
                ],
                // Una cuenta revocada necesita que el organizador reautorice;
                // insistir a diario solo quemaria llamadas contra MercadoPago.
                [AccountMercadopagoPlatformDomainObjectAbstract::REVOKED_AT, 'null', null],
            ];

        // Fetch only the columns needed to drive the loop: hydrating full rows
        // decrypts the token casts, so one corrupted row would abort the whole
        // batch instead of just its own refresh.
        $expiring = $this->platformRepository->findWhere(
            where: [
                ...$scope,
                [AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT, 'not null', null],
            ],
            columns: [
                AccountMercadopagoPlatformDomainObjectAbstract::ID,
                AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID,
                AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT,
            ],
        );

        if ($expiring->isEmpty()) {
            $this->info('No MercadoPago tokens close to expiry.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            /** @var AccountMercadopagoPlatformDomainObject $row */
            foreach ($expiring as $row) {
                $this->line(sprintf(
                    'Would refresh account %d (token expires %s)',
                    $row->getAccountId(),
                    $row->getTokenExpiresAt(),
                ));
            }
            $this->info(sprintf('Dry run: %d token(s) due for refresh.', $expiring->count()));

            return self::SUCCESS;
        }

        $failures = 0;

        /** @var AccountMercadopagoPlatformDomainObject $row */
        foreach ($expiring as $row) {
            if (! $this->refreshPlatform($row->getId(), $row->getAccountId(), $row->getTokenExpiresAt())) {
                $failures++;
            }
        }

        $this->info(sprintf('Refreshed %d of %d token(s).', $expiring->count() - $failures, $expiring->count()));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function refreshPlatform(int $id, int $accountId, ?string $expectedExpiresAt): bool
    {
        try {
            // La fila queda lockeada durante releer-renovar-persistir: el refresh
            // token de MercadoPago es de un solo uso, y una corrida manual con
            // --account cruzada con la de las 05:00 (withoutOverlapping solo
            // serializa al scheduler consigo mismo) quemaria la cadena.
            return (bool) $this->platformRepository->withLockedRow(
                $id,
                function (?AccountMercadopagoPlatformDomainObject $platform) use ($id, $accountId, $expectedExpiresAt): bool {
                    if ($platform === null) {
                        $this->error("Account {$accountId}: connection row disappeared, skipping");

                        return false;
                    }

                    // Otro proceso renovo mientras esperabamos el lock: el
                    // vencimiento guardado ya avanzo. Salir sin quemar el par nuevo.
                    if ($this->alreadyRefreshed($platform, $expectedExpiresAt)) {
                        $this->info("Account {$accountId}: already refreshed by a concurrent run, skipping");

                        return true;
                    }

                    return $this->refreshLockedPlatform($platform, $id, $accountId);
                },
            );
        } catch (MercadoPagoOAuthException $e) {
            return $this->handleOAuthFailure($e, $id, $accountId);
        } catch (Throwable $e) {
            $this->logger->error('MercadoPago token refresh failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $this->error("Account {$accountId}: refresh failed ({$e->getMessage()})");

            return false;
        }
    }

    private function refreshLockedPlatform(
        AccountMercadopagoPlatformDomainObject $platform,
        int $id,
        int $accountId,
    ): bool {
        $refreshToken = $platform->getRefreshToken();

        if (! $refreshToken) {
            $this->logger->error('MercadoPago token refresh skipped: no refresh token stored', [
                'account_id' => $accountId,
            ]);
            $this->error("Account {$accountId}: no refresh token stored, the organizer must reconnect");

            return false;
        }

        $tokenData = $this->oauthService->refreshAccessToken($refreshToken);

        if (empty($tokenData['access_token']) || empty($tokenData['refresh_token'])) {
            $this->logger->error('MercadoPago token refresh returned incomplete data', [
                'account_id' => $accountId,
                'keys' => array_keys($tokenData),
            ]);
            $this->error("Account {$accountId}: incomplete response, stored tokens left untouched");

            return false;
        }

        $expiresAt = Carbon::now()
            ->addSeconds((int) ($tokenData['expires_in'] ?? self::DEFAULT_TOKEN_TTL_DAYS * 86400))
            ->toDateTimeString();

        // The old pair died the moment the refresh call succeeded, so persist the
        // new one before anything else. updateFromArray fills + saves the model,
        // so the `encrypted` casts run (a raw updateWhere would store plaintext —
        // see MercadoPagoOAuthCallbackHandler). A successful refresh proves the
        // grant is alive, so any stale revoked mark is cleared (manual recovery
        // via --account).
        $this->platformRepository->updateFromArray($id, [
            AccountMercadopagoPlatformDomainObjectAbstract::ACCESS_TOKEN => $tokenData['access_token'],
            AccountMercadopagoPlatformDomainObjectAbstract::REFRESH_TOKEN => $tokenData['refresh_token'],
            AccountMercadopagoPlatformDomainObjectAbstract::PUBLIC_KEY => $tokenData['public_key'] ?? $platform->getPublicKey(),
            AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT => $expiresAt,
            AccountMercadopagoPlatformDomainObjectAbstract::REVOKED_AT => null,
        ]);

        $this->logger->info('MercadoPago token refreshed', ['account_id' => $accountId]);
        $this->info("Account {$accountId}: token refreshed (expires {$expiresAt})");

        return true;
    }

    private function handleOAuthFailure(MercadoPagoOAuthException $e, int $id, int $accountId): bool
    {
        if ($e->isTerminal()) {
            // invalid_grant / unauthorized_client: el grant esta muerto y solo el
            // organizador puede revivirlo reautorizando. Se marca la fila (sin
            // borrarla — liberaria el mp_user_id unico) para que la corrida
            // diaria deje de insistir y el checkout esconda MercadoPago.
            $this->platformRepository->updateFromArray($id, [
                AccountMercadopagoPlatformDomainObjectAbstract::REVOKED_AT => Carbon::now()->toDateTimeString(),
            ]);
            $this->logger->error('MercadoPago token refresh rejected: connection revoked, organizer must re-authorize', [
                'account_id' => $accountId,
                'mp_error' => $e->getMpErrorCode(),
            ]);
            $this->error("Account {$accountId}: connection revoked ({$e->getMpErrorCode()}), the organizer must re-authorize");

            return false;
        }

        if ($e->isRetryable()) {
            // 429: la corrida diaria siguiente es el backoff.
            $this->logger->warning('MercadoPago token refresh rate limited, will retry on the next run', [
                'account_id' => $accountId,
            ]);
            $this->error("Account {$accountId}: rate limited by MercadoPago, will retry on the next run");

            return false;
        }

        $this->logger->error('MercadoPago token refresh failed', [
            'account_id' => $accountId,
            'mp_error' => $e->getMpErrorCode(),
            'error' => $e->getMessage(),
        ]);
        $this->error("Account {$accountId}: refresh failed ({$e->getMessage()})");

        return false;
    }

    private function alreadyRefreshed(
        AccountMercadopagoPlatformDomainObject $platform,
        ?string $expectedExpiresAt,
    ): bool {
        $current = $platform->getTokenExpiresAt();

        if ($current === null || $expectedExpiresAt === null) {
            return false;
        }

        // Una renovacion siempre empuja el vencimiento hacia adelante, asi que
        // "mas lejos que lo que vio el scope" significa que otro proceso ya paso.
        return Carbon::parse($current)->gt(Carbon::parse($expectedExpiresAt));
    }
}
