<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers;

use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantConversationService;
use HiEvents\Assistant\Domain\AssistantUsageLimiter;
use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use HiEvents\Assistant\Exceptions\AssistantDisabledException;
use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\DTO\AssistantReplyDTO;
use HiEvents\Assistant\Handlers\DTO\ChatWithAssistantDTO;
use HiEvents\Exceptions\OrganizerNotFoundException;
use Illuminate\Config\Repository as Config;

readonly class ChatWithAssistantHandler
{
    public function __construct(
        private Config                       $config,
        private AssistantContextFactory      $contextFactory,
        private AssistantConversationService $conversation,
        private AssistantUsageLimiter        $usageLimiter,
    )
    {
    }

    /**
     * @throws AssistantDisabledException
     * @throws AssistantUnavailableException
     * @throws AssistantBudgetExceededException
     * @throws OrganizerNotFoundException
     */
    public function handle(ChatWithAssistantDTO $dto): AssistantReplyDTO
    {
        if (!$this->config->get('assistant.enabled')) {
            throw new AssistantDisabledException(__('The assistant is not enabled.'));
        }

        $this->usageLimiter->assertWithinBudget($dto->accountId);

        $context = $this->contextFactory->create(
            user: $dto->user,
            accountId: $dto->accountId,
            organizerId: $dto->organizerId,
        );

        $reply = $this->conversation->converse($context, $dto->messages);

        $this->usageLimiter->record($dto->accountId, $reply->inputTokens, $reply->outputTokens);

        return $reply;
    }
}
