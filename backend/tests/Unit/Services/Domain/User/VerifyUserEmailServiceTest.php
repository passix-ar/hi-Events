<?php

namespace Tests\Unit\Services\Domain\User;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\User\VerifyUserEmailService;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class VerifyUserEmailServiceTest extends TestCase
{
    private VerifyUserEmailService $service;
    private MockInterface|UserRepositoryInterface $userRepository;
    private MockInterface|AccountRepositoryInterface $accountRepository;
    private MockInterface|AccountUserRepositoryInterface $accountUserRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->accountRepository = Mockery::mock(AccountRepositoryInterface::class);
        $this->accountUserRepository = Mockery::mock(AccountUserRepositoryInterface::class);

        $this->service = new VerifyUserEmailService(
            userRepository: $this->userRepository,
            accountRepository: $this->accountRepository,
            accountUserRepository: $this->accountUserRepository,
        );
    }

    public function testReturnsEarlyWhenEmailAlreadyVerified(): void
    {
        $user = new UserDomainObject();
        $user->setId(1);
        $user->setEmailVerifiedAt('2026-01-01 00:00:00');

        // No repository should be touched when the email is already verified.
        $this->userRepository->shouldNotReceive('updateWhere');
        $this->accountUserRepository->shouldNotReceive('findFirstWhere');
        $this->accountRepository->shouldNotReceive('updateWhere');

        $this->service->markEmailAsVerified($user, 10);

        $this->assertTrue(true);
    }

    public function testMarksEmailAndAccountVerifiedForOwner(): void
    {
        $user = new UserDomainObject();
        $user->setId(1);
        $user->setEmailVerifiedAt(null);

        $this->userRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(
                Mockery::on(fn($attributes) => array_key_exists('email_verified_at', $attributes)),
                ['id' => 1],
            );

        $accountUser = new AccountUserDomainObject();
        $accountUser->setIsAccountOwner(true);

        $this->accountUserRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['user_id' => 1, 'account_id' => 10])
            ->andReturn($accountUser);

        $this->accountRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(
                Mockery::on(fn($attributes) => array_key_exists('account_verified_at', $attributes)),
                ['id' => 10],
            );

        $this->service->markEmailAsVerified($user, 10);

        $this->assertTrue(true);
    }

    public function testMarksOnlyEmailVerifiedForNonOwner(): void
    {
        $user = new UserDomainObject();
        $user->setId(2);
        $user->setEmailVerifiedAt(null);

        $this->userRepository
            ->shouldReceive('updateWhere')
            ->once()
            ->with(
                Mockery::on(fn($attributes) => array_key_exists('email_verified_at', $attributes)),
                ['id' => 2],
            );

        $accountUser = new AccountUserDomainObject();
        $accountUser->setIsAccountOwner(false);

        $this->accountUserRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['user_id' => 2, 'account_id' => 20])
            ->andReturn($accountUser);

        // The account itself should not be verified for a non-owner.
        $this->accountRepository->shouldNotReceive('updateWhere');

        $this->service->markEmailAsVerified($user, 20);

        $this->assertTrue(true);
    }

    public function testThrowsWhenAccountUserNotFound(): void
    {
        $user = new UserDomainObject();
        $user->setId(3);
        $user->setEmailVerifiedAt(null);

        $this->userRepository
            ->shouldReceive('updateWhere')
            ->once();

        $this->accountUserRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['user_id' => 3, 'account_id' => 30])
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->service->markEmailAsVerified($user, 30);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
