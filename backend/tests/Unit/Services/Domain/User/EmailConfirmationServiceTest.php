<?php

namespace Tests\Unit\Services\Domain\User;

use Carbon\Carbon;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use HiEvents\Services\Domain\User\VerifyUserEmailService;
use HiEvents\Services\Infrastructure\Encryption\EncryptedPayloadService;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class EmailConfirmationServiceTest extends TestCase
{
    private EmailConfirmationService $service;
    private MockInterface|EncryptedPayloadService $encryptedPayloadService;
    private MockInterface|UserRepositoryInterface $userRepository;
    private MockInterface|VerifyUserEmailService $verifyUserEmailService;
    private MockInterface|AccountUserRepositoryInterface $accountUserRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptedPayloadService = Mockery::mock(EncryptedPayloadService::class);
        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->verifyUserEmailService = Mockery::mock(VerifyUserEmailService::class);
        $this->accountUserRepository = Mockery::mock(AccountUserRepositoryInterface::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn($callback) => $callback());

        $this->service = new EmailConfirmationService(
            mailer: Mockery::mock(Mailer::class),
            encryptedPayloadService: $this->encryptedPayloadService,
            userRepository: $this->userRepository,
            databaseManager: $databaseManager,
            emailVerificationCodeService: Mockery::mock(EmailVerificationCodeService::class),
            verifyUserEmailService: $this->verifyUserEmailService,
            eventRepository: Mockery::mock(EventRepositoryInterface::class),
            accountUserRepository: $this->accountUserRepository,
        );
    }

    public function testConfirmsUsingAccountIdFromToken(): void
    {
        $this->encryptedPayloadService
            ->shouldReceive('decryptPayload')
            ->once()
            ->with('valid-token')
            ->andReturn(['id' => 7, 'account_id' => 99, 'exp' => Carbon::now()->addHour()->toIso8601String()]);

        $user = new UserDomainObject();
        $user->setId(7);

        $this->userRepository
            ->shouldReceive('findByIdAndAccountId')
            ->once()
            ->with(7, 99)
            ->andReturn($user);

        // No fallback lookup should happen when the token already carries the account id.
        $this->accountUserRepository->shouldNotReceive('findFirstWhere');

        $this->verifyUserEmailService
            ->shouldReceive('markEmailAsVerified')
            ->once()
            ->with($user, 99);

        $this->service->confirmEmailAddressFromToken('valid-token');

        $this->assertTrue(true);
    }

    public function testFallsBackToOwnerAccountForLegacyTokenWithoutAccountId(): void
    {
        $this->encryptedPayloadService
            ->shouldReceive('decryptPayload')
            ->once()
            ->with('legacy-token')
            ->andReturn(['id' => 7, 'exp' => Carbon::now()->addHour()->toIso8601String()]);

        $accountUser = new AccountUserDomainObject();
        $accountUser->setAccountId(42);

        $this->accountUserRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['user_id' => 7, 'is_account_owner' => true])
            ->andReturn($accountUser);

        $user = new UserDomainObject();
        $user->setId(7);

        $this->userRepository
            ->shouldReceive('findByIdAndAccountId')
            ->once()
            ->with(7, 42)
            ->andReturn($user);

        $this->verifyUserEmailService
            ->shouldReceive('markEmailAsVerified')
            ->once()
            ->with($user, 42);

        $this->service->confirmEmailAddressFromToken('legacy-token');

        $this->assertTrue(true);
    }

    public function testPropagatesWhenUserNotFoundInAccount(): void
    {
        $this->encryptedPayloadService
            ->shouldReceive('decryptPayload')
            ->once()
            ->andReturn(['id' => 7, 'account_id' => 99, 'exp' => Carbon::now()->addHour()->toIso8601String()]);

        // The repository throws when the user is not part of the account.
        $this->userRepository
            ->shouldReceive('findByIdAndAccountId')
            ->once()
            ->with(7, 99)
            ->andThrow(new ResourceNotFoundException());

        $this->verifyUserEmailService->shouldNotReceive('markEmailAsVerified');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->confirmEmailAddressFromToken('valid-token');
    }

    public function testThrowsWhenLegacyTokenHasNoOwnerAccount(): void
    {
        $this->encryptedPayloadService
            ->shouldReceive('decryptPayload')
            ->once()
            ->andReturn(['id' => 7, 'exp' => Carbon::now()->addHour()->toIso8601String()]);

        $this->accountUserRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['user_id' => 7, 'is_account_owner' => true])
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->service->confirmEmailAddressFromToken('legacy-token');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
