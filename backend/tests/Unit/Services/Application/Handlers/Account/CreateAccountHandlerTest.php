<?php

namespace Tests\Unit\Services\Application\Handlers\Account;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountAttributionRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountConfigurationRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\CreateAccountHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateAccountDTO;
use HiEvents\Services\Domain\Account\AccountUserAssociationService;
use HiEvents\Services\Domain\User\EmailConfirmationService;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Hashing\HashManager;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CreateAccountHandlerTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private AccountRepositoryInterface $accountRepository;
    private HashManager $hashManager;
    private DatabaseManager $databaseManager;
    private Repository $config;
    private EmailConfirmationService $emailConfirmationService;
    private AccountUserAssociationService $accountUserAssociationService;
    private AccountUserRepositoryInterface $accountUserRepository;
    private AccountConfigurationRepositoryInterface $accountConfigurationRepository;
    private AccountAttributionRepositoryInterface $accountAttributionRepository;
    private CreateAccountHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->accountRepository = Mockery::mock(AccountRepositoryInterface::class);
        $this->hashManager = Mockery::mock(HashManager::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->config = Mockery::mock(Repository::class);
        $this->emailConfirmationService = Mockery::mock(EmailConfirmationService::class);
        $this->accountUserRepository = Mockery::mock(AccountUserRepositoryInterface::class);
        // The service is a readonly class, which Mockery cannot subclass, so the real
        // one runs here against the mocked repository.
        $this->accountUserAssociationService = new AccountUserAssociationService($this->accountUserRepository);
        $this->accountConfigurationRepository = Mockery::mock(AccountConfigurationRepositoryInterface::class);
        $this->accountAttributionRepository = Mockery::mock(AccountAttributionRepositoryInterface::class);

        $this->handler = new CreateAccountHandler(
            $this->userRepository,
            $this->accountRepository,
            $this->hashManager,
            $this->databaseManager,
            $this->config,
            $this->emailConfirmationService,
            $this->accountUserAssociationService,
            $this->accountUserRepository,
            $this->accountConfigurationRepository,
            $this->accountAttributionRepository,
            Mockery::mock(LoggerInterface::class),
        );
    }

    public function testAccountNameComesFromBusinessNameAndIsTrimmed(): void
    {
        $this->config
            ->shouldReceive('get')
            ->andReturnUsing(fn (string $key) => match ($key) {
                'app.disable_registration' => false,
                'app.saas_mode_enabled' => false,
                'app.is_hi_events' => false,
                default => null,
            });

        $this->hashManager->shouldReceive('make')->andReturn('hashed-password');

        // Execute the transaction closure inline.
        $this->databaseManager
            ->shouldReceive('transaction')
            ->andReturnUsing(fn (\Closure $callback) => $callback());

        $configuration = Mockery::mock(AccountConfigurationDomainObject::class);
        $configuration->shouldReceive('getId')->andReturn(1);
        $this->accountConfigurationRepository
            ->shouldReceive('findFirstWhere')
            ->with(['is_system_default' => true])
            ->andReturn($configuration);

        $account = Mockery::mock(AccountDomainObject::class);
        $account->shouldReceive('getId')->andReturn(10);

        // Core assertion: the account name is the (trimmed) business_name,
        // NOT first_name + last_name.
        $this->accountRepository
            ->shouldReceive('create')
            ->withArgs(fn (array $attributes) => $attributes['name'] === 'My Business SA')
            ->once()
            ->andReturn($account);

        $user = Mockery::mock(UserDomainObject::class);
        $user->shouldReceive('getId')->andReturn(20);
        $this->userRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->userRepository->shouldReceive('create')->andReturn($user);

        $this->accountUserRepository
            ->shouldReceive('create')
            ->withArgs(fn (array $attributes) => $attributes['user_id'] === 20
                && $attributes['account_id'] === 10
                && $attributes['is_account_owner'] === true)
            ->once()
            ->andReturn(Mockery::mock(AccountUserDomainObject::class));
        $this->emailConfirmationService->shouldReceive('sendConfirmation')->once();

        $dto = new CreateAccountDTO(
            email: 'owner@example.com',
            password: 'password123',
            first_name: 'John',
            business_name: '  My Business SA  ',
            locale: 'es',
            last_name: 'Smith',
            timezone: 'UTC',
            currency_code: 'ARS',
        );

        $result = $this->handler->handle($dto);

        $this->assertSame($account, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
