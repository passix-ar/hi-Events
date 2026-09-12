<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Assistant\Handlers\DTO\AssistantReplyDTO;
use HiEvents\Assistant\Handlers\DTO\AssistantToolCallDTO;
use Illuminate\Config\Repository as Config;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The only class that talks to the LLM provider. Swapping Prism for the
 * official SDK (or another provider) means rewriting this file and nothing else.
 */
readonly class AssistantConversationService
{
    public function __construct(
        private AssistantToolRegistry        $toolRegistry,
        private AssistantSystemPromptBuilder $promptBuilder,
        private Config                       $config,
        private LoggerInterface              $logger,
    )
    {
    }

    /**
     * @param list<AssistantMessageDTO> $history
     * @throws AssistantUnavailableException
     */
    public function converse(AssistantContext $context, array $history): AssistantReplyDTO
    {
        $startedAt = microtime(true);

        try {
            $response = Prism::text()
                ->using(
                    $this->config->get('assistant.provider'),
                    $this->config->get('assistant.model'),
                )
                ->withSystemPrompt($this->promptBuilder->build($context))
                ->withMessages($this->toPrismMessages($history))
                ->withTools($this->toolRegistry->forContext($context))
                ->withMaxSteps((int)$this->config->get('assistant.max_steps'))
                ->withMaxTokens((int)$this->config->get('assistant.max_tokens'))
                ->withClientOptions(['timeout' => (int)$this->config->get('assistant.request_timeout')])
                ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']])
                ->asText();
        } catch (PrismException $e) {
            $this->logger->error('assistant.provider.failed', [
                'organizer_id' => $context->organizerId,
                'exception' => $e,
            ]);

            throw new AssistantUnavailableException(__('The assistant is temporarily unavailable.'), previous: $e);
        } catch (Throwable $e) {
            $this->logger->error('assistant.conversation.failed', [
                'organizer_id' => $context->organizerId,
                'exception' => $e,
            ]);

            throw new AssistantUnavailableException(__('The assistant is temporarily unavailable.'), previous: $e);
        }

        $toolCalls = $this->collectToolCalls($response);

        $this->logger->info('assistant.conversation', [
            'organizer_id' => $context->organizerId,
            'user_id' => $context->user->getId(),
            'steps' => $response->steps->count(),
            'tools' => array_map(static fn(AssistantToolCallDTO $c): string => $c->name, $toolCalls),
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'cache_read_tokens' => $response->usage->cacheReadInputTokens,
            'finish_reason' => $response->finishReason->name,
            'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
        ]);

        return new AssistantReplyDTO(
            reply: trim($response->text),
            toolCalls: $toolCalls,
            inputTokens: $response->usage->promptTokens,
            outputTokens: $response->usage->completionTokens,
        );
    }

    /**
     * @param list<AssistantMessageDTO> $history
     * @return list<UserMessage|AssistantMessage>
     */
    private function toPrismMessages(array $history): array
    {
        return array_map(
            static fn(AssistantMessageDTO $m): UserMessage|AssistantMessage => $m->role === AssistantMessageDTO::ROLE_ASSISTANT
                ? new AssistantMessage($m->content)
                : new UserMessage($m->content),
            $history,
        );
    }

    /**
     * @return list<AssistantToolCallDTO>
     */
    private function collectToolCalls(Response $response): array
    {
        return $response->steps
            ->flatMap(static fn(Step $step): array => $step->toolCalls)
            ->map(static fn(ToolCall $call): AssistantToolCallDTO => new AssistantToolCallDTO(
                name: $call->name,
                arguments: $call->arguments(),
            ))
            ->values()
            ->all();
    }
}
