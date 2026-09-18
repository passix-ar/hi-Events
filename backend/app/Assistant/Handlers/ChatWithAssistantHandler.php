<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers;

use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantConversationService;
use HiEvents\Assistant\Domain\AssistantUsageLimiter;
use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use HiEvents\Assistant\Exceptions\AssistantDisabledException;
use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
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

        // Only the most recent turns reach the model; the last one is always the user's.
        $messages = array_slice($dto->messages, -max((int)$this->config->get('assistant.max_history_messages', 16), 1));

        $context = $this->contextFactory->create(
            user: $dto->user,
            accountId: $dto->accountId,
            organizerId: $dto->organizerId,
            focusedEventId: $dto->focusedEventId,
            attachmentId: $dto->attachmentId,
            // Ids come from the whole history, even the turns trimmed away below.
            knownEntities: array_merge([], ...array_map(
                static fn(AssistantMessageDTO $m): array => $m->entities,
                $dto->messages,
            )),
        );

        $reply = $this->conversation->converse($context, $messages);

        $this->usageLimiter->record($dto->accountId, $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens);

        return $reply;
    }
}
