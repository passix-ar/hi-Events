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
        $this->givenStoredPlatform($this->row(refreshToken: 'old-refresh', publicKey: 'stored-public-key'));

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->with('old-refresh')
            ->andReturn([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'public_key' => 'new-public-key',
                'expires_in' => 15552000,
            ]);

        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(self::PLATFORM_ID, m::on(static function (array $attributes) {
                return $attributes['access_token'] === 'new-access'
                    && $attributes['refresh_token'] === 'new-refresh'
                    && $attributes['public_key'] === 'new-public-key'
                    && ! empty($attributes['token_expires_at']);
            }))
            ->andReturn(new AccountMercadopagoPlatformDomainObject);

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    public function test_does_not_persist_when_response_is_incomplete(): void
    {
        $this->givenExpiringRows([$this->row()]);
        $this->givenStoredPlatform($this->row(refreshToken: 'old-refresh'));

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

        $this->platformRepository->shouldReceive('findFirstWhere')
            ->with(['id' => self::PLATFORM_ID])
            ->andReturn($this->row(refreshToken: 'broken-refresh'));
        $this->platformRepository->shouldReceive('findFirstWhere')
            ->with(['id' => 20])
            ->andReturn($this->row(id: 20, accountId: 11, refreshToken: 'healthy-refresh'));

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
        $this->givenStoredPlatform($this->row(refreshToken: null));

        $this->oauthService->shouldNotReceive('refreshAccessToken');
        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(1);
    }

    public function test_dry_run_does_not_call_mercado_pago(): void
    {
        $this->givenExpiringRows([$this->row()]);

        $this->oauthService->shouldNotReceive('refreshAccessToken');
        $this->platformRepository->shouldNotReceive('findFirstWhere');
        $this->platformRepository->shouldNotReceive('updateFromArray');

        $this->artisan('mercadopago:refresh-tokens', ['--dry-run' => true])->assertExitCode(0);
    }

    public function test_does_nothing_when_no_tokens_are_close_to_expiry(): void
    {
        $this->givenExpiringRows([]);

        $this->oauthService->shouldNotReceive('refreshAccessToken');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);
    }

    private function givenExpiringRows(array $rows): void
    {
        $this->platformRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect($rows));
    }

    private function givenStoredPlatform(AccountMercadopagoPlatformDomainObject $platform): void
    {
        $this->platformRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => $platform->getId()])
            ->andReturn($platform);
    }

    private function row(
        int $id = self::PLATFORM_ID,
        int $accountId = self::ACCOUNT_ID,
        ?string $refreshToken = null,
        ?string $publicKey = null,
    ): AccountMercadopagoPlatformDomainObject {
        return (new AccountMercadopagoPlatformDomainObject)
            ->setId($id)
            ->setAccountId($accountId)
            ->setRefreshToken($refreshToken)
            ->setPublicKey($publicKey)
            ->setTokenExpiresAt('2026-12-21 21:31:00');
    }
}
