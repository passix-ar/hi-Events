<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Carbon\Carbon;
use HiEvents\Models\Account;
use HiEvents\Models\AccountMercadopagoPlatform;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery as m;
use Tests\TestCase;

/**
 * The unit tests mock the repository, so nothing there proves that the
 * scope, the row lock and the encrypted casts work against the real
 * database. This does: only MercadoPago itself is mocked.
 */
class RefreshMercadoPagoTokensCommandTest extends TestCase
{
    use DatabaseTransactions;

    private MercadoPagoOAuthService $oauthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauthService = m::mock(MercadoPagoOAuthService::class);
        $this->app->instance(MercadoPagoOAuthService::class, $this->oauthService);
    }

    public function test_refreshes_the_row_inside_the_window_and_stores_the_new_pair_encrypted(): void
    {
        $platform = $this->connectedPlatform(expiresInDays: 10, refreshToken: 'TG-old');

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->with('TG-old')
            ->andReturn([
                'access_token' => 'APP_USR-new',
                'refresh_token' => 'TG-new',
                'public_key' => 'APP_PUB-new',
                'expires_in' => 180 * 86400,
            ]);

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);

        $fresh = AccountMercadopagoPlatform::findOrFail($platform->id);

        // Read through the model so the `encrypted` casts decrypt: a plaintext
        // write would blow up here, not silently pass.
        $this->assertSame('APP_USR-new', $fresh->access_token);
        $this->assertSame('TG-new', $fresh->refresh_token);
        $this->assertSame('APP_PUB-new', $fresh->public_key);
        $this->assertTrue($fresh->token_expires_at->gt(Carbon::now()->addDays(179)));

        // And confirm the column really is ciphertext, not the token itself.
        $raw = AccountMercadopagoPlatform::query()->toBase()->where('id', $platform->id)->value('access_token');
        $this->assertNotSame('APP_USR-new', $raw);
    }

    public function test_leaves_rows_outside_the_window_untouched(): void
    {
        $farAway = $this->connectedPlatform(expiresInDays: 60, refreshToken: 'TG-far');
        $expired = $this->connectedPlatform(expiresInDays: -1, refreshToken: 'TG-expired');
        $incomplete = $this->connectedPlatform(expiresInDays: 10, refreshToken: 'TG-incomplete', setupCompleted: false);

        $this->oauthService->shouldNotReceive('refreshAccessToken');

        $this->artisan('mercadopago:refresh-tokens')->assertExitCode(0);

        foreach ([$farAway, $expired, $incomplete] as $platform) {
            $fresh = AccountMercadopagoPlatform::findOrFail($platform->id);
            $this->assertSame($platform->refresh_token, $fresh->refresh_token);
            $this->assertEquals($platform->token_expires_at, $fresh->token_expires_at);
        }
    }

    public function test_account_option_reaches_an_already_expired_row(): void
    {
        $expired = $this->connectedPlatform(expiresInDays: -1, refreshToken: 'TG-expired');

        $this->oauthService->shouldReceive('refreshAccessToken')
            ->once()
            ->with('TG-expired')
            ->andReturn([
                'access_token' => 'APP_USR-recovered',
                'refresh_token' => 'TG-recovered',
                'expires_in' => 180 * 86400,
            ]);

        $this->artisan('mercadopago:refresh-tokens', ['--account' => $expired->account_id])->assertExitCode(0);

        $fresh = AccountMercadopagoPlatform::findOrFail($expired->id);
        $this->assertSame('TG-recovered', $fresh->refresh_token);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    private function connectedPlatform(
        int $expiresInDays,
        string $refreshToken,
        bool $setupCompleted = true,
    ): AccountMercadopagoPlatform {
        $account = Account::factory()->create();

        return AccountMercadopagoPlatform::create([
            'account_id' => $account->id,
            'mp_user_id' => (string) random_int(100000, 999999),
            'access_token' => 'APP_USR-'.$refreshToken,
            'refresh_token' => $refreshToken,
            'public_key' => 'APP_PUB-'.$refreshToken,
            'token_expires_at' => Carbon::now()->addDays($expiresInDays),
            'setup_completed_at' => $setupCompleted ? Carbon::now()->subMonths(5) : null,
        ]);
    }
}
