<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers;

use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantConversationService;
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
    )
    {
    }

    /**
     * @throws AssistantDisabledException
     * @throws AssistantUnavailableException
     * @throws OrganizerNotFoundException
     */
    public function handle(ChatWithAssistantDTO $dto): AssistantReplyDTO
    {
        if (!$this->config->get('assistant.enabled')) {
            throw new AssistantDisabledException(__('The assistant is not enabled.'));
        }

        $context = $this->contextFactory->create(
            user: $dto->user,
            accountId: $dto->accountId,
            organizerId: $dto->organizerId,
        );

        return $this->conversation->converse($context, $dto->messages);
    }
}
