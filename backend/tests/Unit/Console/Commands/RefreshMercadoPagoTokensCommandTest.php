<?php

namespace Tests\Unit\Console\Commands;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use Tests\TestCase;

class RefreshMercadoPagoTokensCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const PLATFORM_ID = 19;

    private const ACCOUNT_ID = 10;

    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;

    private MercadoPagoOAuthService $oauthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformRepository = m::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $this->oauthService = m::mock(MercadoPagoOAuthService::class);

        $this->app->instance(AccountMercadopagoPlatformRepositoryInterface::class, $this->platformRepository);
        $this->app->instance(MercadoPagoOAuthService::class, $this->oauthService);
    }

    public function test_refreshes_expiring_token_and_persists_the_new_pair(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: 'old-refresh', publicKey: 'stored-public-key'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->with('old-refresh')
            ->andReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'public_key' => 'new-public-key',
                'expires_in' => 15552000,
            ]);

        // Ademas del par nuevo, un refresh exitoso limpia la marca de revocada:
        // es la via de recuperacion manual de una cuenta marcada (--account).
        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(self::PLATFORM_ID, m::on(static function (array $attributes) {
                return $attributes['access_token'] === 'new-access'
                    && $attributes['refresh_token'] === 'new-refresh'
                    && $attributes['public_key'] === 'new-public-key'
                    && ! empty($attributes['token_expires_at'])
                    && array_key_exists('revoked_at', $attributes)
                    && $attributes['revoked_at'] === null;
            }))
            ->andReturn(new AccountMercadopagoPlatformDomainObject);

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    public function test_does_not_persist_when_response_is_incomplete(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: 'old-refresh'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->andReturn(['access_token' => 'new-access']);

        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_continues_with_remaining_accounts_when_one_fails(): void
    {
        $failing = $this->row();
        $healthy = $this->row(id: 20, accountId: 11);

        $this->givenExpiringRows([$failing, $healthy]);

        $this->givenLockedPlatform($this->row(refreshToken: 'broken-refresh'));
        $this->givenLockedPlatform($this->row(id: 20, accountId: 11, refreshToken: 'healthy-refresh'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->with('broken-refresh')
            ->andThrow(new MercadoPagoOAuthException('MercadoPago rejected the refresh'));
        $this->oauthService->shouldReceive('refreshAccessToken')
            ->with('healthy-refresh')
            ->andReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 15552000,
            ]);

        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(20, m::type('array'))
            ->andReturn(new AccountMercadopagoPlatformDomainObject);

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_fails_when_no_refresh_token_is_stored(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: null));

        $this->oauthService->shouldNotReceive('refreshAccessToken');
        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_dry_run_does_not_call_mercado_pago(): void
    {
        $this->givenExpiringRows([$this->row()]);

        $this->oauthService->shouldNotReceive('refreshAccessToken');
        $this->platformRepository->shouldNotReceive('withLockedRow');
        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens', ['--dry-run' => true])->assertExitCode(0);
    }

    public function test_does_nothing_when_no_tokens_are_close_to_expiry(): void
    {
        $this->givenExpiringRows([]);

        $this->oauthService->shouldNotReceive('refreshAccessToken');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    public function test_window_has_a_floor_and_excludes_revoked_connections(): void
    {
        // Sin piso en now() la corrida diaria martillaria para siempre las
        // cuentas ya vencidas; sin el filtro de revoked_at, las que MercadoPago
        // rechazo con un error terminal.
        $this->platformRepository->shouldReceive('findWhere')
            ->once()
            ->with(
                m::on(static function (array $where): bool {
                    $tokenOps = [];
                    $revokedNull = false;

                    foreach ($where as $condition) {
                        [$campo, $operador] = $condition;

                        if ($campo === 'token_expires_at') {
                            $tokenOps[] = $operador;
                        }

                        if ($campo === 'revoked_at' && strtolower($operador) === 'null') {
                            $revokedNull = true;
                        }
                    }

                    return in_array('>=', $tokenOps, true)
                        && in_array('<', $tokenOps, true)
                        && $revokedNull;
                }),
                m::any(),
            )
            ->andReturn(collect());

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    public function test_account_option_targets_one_account_and_ignores_window_and_revoked_mark(): void
    {
        // Con --account el filtro tiene que ser por account_id y NO por fecha de
        // vencimiento ni marca de revocada: es la via de recuperacion manual, y
        // una cuenta colgada esta vencida o revocada por definicion.
        $this->platformRepository->shouldReceive('findWhere')
            ->once()
            ->with(
                m::on(function (array $where): bool {
                    $campos = array_column($where, 0);

                    return in_array('account_id', $campos, true)
                        && ! in_array('token_expires_at', $campos, true)
                        && ! in_array('revoked_at', $campos, true);
                }),
                m::any(),
            )
            ->andReturn(collect([$this->row(tokenExpiresAt: '2020-01-01 00:00:00')]));

        $this->givenLockedPlatform($this->row(refreshToken: 'old-refresh', tokenExpiresAt: '2020-01-01 00:00:00'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->andReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 15552000,
            ]);

        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->andReturn(new AccountMercadopagoPlatformDomainObject);

        $this->artisan('mercadopago:refresh-tokens', ['--account' => self::ACCOUNT_ID])->assertExitCode(0);
    }

    public function test_terminal_error_marks_the_connection_revoked(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: 'dead-refresh'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->with('dead-refresh')
            ->andThrow(new MercadoPagoOAuthException('rejected', mpErrorCode: 'invalid_grant'));

        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(self::PLATFORM_ID, m::on(static function (array $attributes) {
                return ! empty($attributes['revoked_at']) && count($attributes) === 1;
            }))
            ->andReturn(new AccountMercadopagoPlatformDomainObject);

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_rate_limited_error_does_not_mark_the_connection_revoked(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: 'throttled-refresh'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->andThrow(new MercadoPagoOAuthException('throttled', mpErrorCode: 'local_rate_limited'));

        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_skips_without_burning_the_token_when_a_concurrent_run_already_refreshed(): void
    {
        // El refresh token es de un solo uso: si otro proceso renovo mientras
        // esperabamos el lock, el vencimiento guardado ya avanzo y reintentar
        // mandaria un token quemado.
        $this->givenExpiringRows([$this->row()]);
        $this->givenLockedPlatform($this->row(refreshToken: 'fresh-refresh', tokenExpiresAt: '2027-06-01 00:00:00'));

        $this->oauthService->shouldNotReceive('refreshAccessToken');
        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    private function givenExpiringRows(array $rows): void
    {
        $this->platformRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect($rows));
    }

    private function givenLockedPlatform(AccountMercadopagoPlatformDomainObject $platform): void
    {
        $this->platformRepository->shouldReceive('withLockedRow')
            ->once()
            ->with($platform->getId(), m::type('callable'))
            ->andReturnUsing(static fn (int $id, callable $operation) => $operation($platform));
    }

    private function row(
        int $id = self::PLATFORM_ID,
        int $accountId = self::ACCOUNT_ID,
        ?string $refreshToken = null,
        ?string $publicKey = null,
        string $tokenExpiresAt = '2026-12-21 21:31:00',
    ): AccountMercadopagoPlatformDomainObject {
        return (new AccountMercadopagoPlatformDomainObject)
            ->setId($id)
            ->setAccountId($accountId)
            ->setRefreshToken($refreshToken)
            ->setPublicKey($publicKey)
            ->setTokenExpiresAt($tokenExpiresAt);
    }
}
