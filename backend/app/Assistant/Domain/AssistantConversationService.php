<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Assistant\Handlers\DTO\AssistantReplyDTO;
use HiEvents\Assistant\Handlers\DTO\AssistantToolCallDTO;
use Illuminate\Config\Repository as Config;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Media\Image;
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
        private AssistantAttachmentStore     $attachments,
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
            $response = $this->pendingRequest($context, $history)->asText();
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
     * Streaming twin of converse(): same request, same log line, same reply DTO,
     * but text and tool calls are handed to $emit as they arrive.
     *
     * $emit receives ('delta', ['text' => string]) for every text chunk and
     * ('tool', ['name' => string, 'arguments' => array]) when the model calls a
     * tool. Nothing Prism-specific leaks through it.
     *
     * @param list<AssistantMessageDTO> $history
     * @param callable(string $event, array<string, mixed> $data): void $emit
     * @throws AssistantUnavailableException
     */
    public function stream(AssistantContext $context, array $history, callable $emit): AssistantReplyDTO
    {
        $startedAt = microtime(true);

        $text = '';
        $toolCalls = [];
        $steps = 0;
        $usage = null;
        $finishReason = null;

        try {
            foreach ($this->pendingRequest($context, $history)->asStream() as $event) {
                if ($event instanceof TextDeltaEvent) {
                    $text .= $event->delta;
                    $emit('delta', ['text' => $event->delta]);
                } elseif ($event instanceof ToolCallEvent) {
                    $call = new AssistantToolCallDTO(
                        name: $event->toolCall->name,
                        arguments: $event->toolCall->arguments(),
                    );
                    $toolCalls[] = $call;
                    $emit('tool', ['name' => $call->name, 'arguments' => $call->arguments]);
                } elseif ($event instanceof StepFinishEvent) {
                    $steps++;
                } elseif ($event instanceof StreamEndEvent) {
                    $usage = $event->usage;
                    $finishReason = $event->finishReason;
                } elseif ($event instanceof ErrorEvent) {
                    throw new AssistantUnavailableException(
                        __('The assistant is temporarily unavailable.'),
                        previous: new PrismException(sprintf('%s: %s', $event->errorType, $event->message)),
                    );
                }
            }
        } catch (PrismException $e) {
            $this->logger->error('assistant.provider.failed', [
                'organizer_id' => $context->organizerId,
                'exception' => $e,
            ]);

            throw new AssistantUnavailableException(__('The assistant is temporarily unavailable.'), previous: $e);
        } catch (AssistantUnavailableException $e) {
            $this->logger->error('assistant.provider.failed', [
                'organizer_id' => $context->organizerId,
                'exception' => $e->getPrevious() ?? $e,
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('assistant.conversation.failed', [
                'organizer_id' => $context->organizerId,
                'exception' => $e,
            ]);

            throw new AssistantUnavailableException(__('The assistant is temporarily unavailable.'), previous: $e);
        }

        $inputTokens = $usage?->promptTokens ?? 0;
        $outputTokens = $usage?->completionTokens ?? 0;

        $this->logger->info('assistant.conversation', [
            'organizer_id' => $context->organizerId,
            'user_id' => $context->user->getId(),
            'steps' => $steps,
            'tools' => array_map(static fn(AssistantToolCallDTO $c): string => $c->name, $toolCalls),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_read_tokens' => $usage?->cacheReadInputTokens,
            'finish_reason' => $finishReason?->name ?? 'Unknown',
            'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
            'streamed' => true,
        ]);

        return new AssistantReplyDTO(
            reply: trim($text),
            toolCalls: $toolCalls,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    /**
     * @param list<AssistantMessageDTO> $history
     */
    private function pendingRequest(AssistantContext $context, array $history): PendingRequest
    {
        return Prism::text()
            ->using(
                $this->config->get('assistant.provider'),
                $this->config->get('assistant.model'),
            )
            ->withSystemPrompt($this->promptBuilder->build($context))
            ->withMessages($this->toPrismMessages($history, $context))
            ->withTools($this->toolRegistry->forContext($context))
            ->withMaxSteps((int)$this->config->get('assistant.max_steps'))
            ->withMaxTokens((int)$this->config->get('assistant.max_tokens'))
            ->withClientOptions(['timeout' => (int)$this->config->get('assistant.request_timeout')])
            ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);
    }

    /**
     * The attachment rides on the last user message. The frontend keeps sending
     * its id until a tool consumes it, so the model can re-read the flyer while it
     * confirms details; once attach_flyer_to_event deletes it, the id resolves to
     * nothing and no image is embedded.
     *
     * @param list<AssistantMessageDTO> $history
     * @return list<UserMessage|AssistantMessage>
     */
    private function toPrismMessages(array $history, AssistantContext $context): array
    {
        $lastIndex = array_key_last($history);

        return array_map(
            function (AssistantMessageDTO $m, int $index) use ($context, $lastIndex): UserMessage|AssistantMessage {
                if ($m->role === AssistantMessageDTO::ROLE_ASSISTANT) {
                    return new AssistantMessage($m->content);
                }

                $media = [];
                if ($index === $lastIndex && $context->attachment !== null) {
                    $media[] = Image::fromRawContent(
                        $this->attachments->contents($context->attachment),
                        $context->attachment->mimeType,
                    );
                }

                return new UserMessage($m->content, $media);
            },
            $history,
            array_keys($history),
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
