<?php

namespace Tests\Unit\Models;

use HiEvents\Models\AccountMercadopagoPlatform;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AccountMercadopagoPlatformTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');
        $this->app->instance('encrypter', $encrypter);
        Crypt::clearResolvedInstances();
    }

    public function test_access_and_refresh_tokens_are_encrypted_at_rest(): void
    {
        $model = new AccountMercadopagoPlatform();
        $model->access_token = 'APP_USR-secret-access';
        $model->refresh_token = 'TG-secret-refresh';

        $rawAccessToken = $model->getAttributes()['access_token'];
        $rawRefreshToken = $model->getAttributes()['refresh_token'];

        $this->assertNotSame('APP_USR-secret-access', $rawAccessToken);
        $this->assertNotSame('TG-secret-refresh', $rawRefreshToken);
        $this->assertSame('APP_USR-secret-access', Crypt::decryptString($rawAccessToken));
        $this->assertSame('TG-secret-refresh', Crypt::decryptString($rawRefreshToken));
    }

    public function test_tokens_are_decrypted_when_read(): void
    {
        $model = new AccountMercadopagoPlatform();
        $model->access_token = 'APP_USR-secret-access';

        $this->assertSame('APP_USR-secret-access', $model->access_token);
    }
}
