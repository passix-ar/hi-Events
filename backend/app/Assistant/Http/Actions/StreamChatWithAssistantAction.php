<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Actions;

use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use HiEvents\Assistant\Exceptions\AssistantDisabledException;
use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Assistant\Handlers\DTO\AssistantToolCallDTO;
use HiEvents\Assistant\Handlers\DTO\ChatWithAssistantDTO;
use HiEvents\Assistant\Handlers\StreamChatWithAssistantHandler;
use HiEvents\Assistant\Http\Requests\ChatWithAssistantRequest;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Server-Sent Events twin of ChatWithAssistantAction. Our own protocol, four events:
 *
 *   event: delta   data: {"text": "..."}
 *   event: tool    data: {"name": "...", "arguments": {...}}
 *   event: tool_done data: {"name": "...", "success": bool, "entities": [...]}
 *   event: done    data: {"reply": "...", "tool_calls": [...], "entities": [...], "input_tokens": n, "output_tokens": n}
 *   event: error   data: {"message": "...", "status": 404|429|503}
 *
 * The HTTP status is always 200 once the stream opens; failures that the JSON
 * endpoint maps to a status code arrive here as an `error` event carrying it.
 * Auth (401), authorization (403) and validation (422) still fail before the
 * stream opens, as plain JSON responses.
 */
class StreamChatWithAssistantAction extends BaseAction
{
    public function __construct(
        private readonly StreamChatWithAssistantHandler $handler,
    )
    {
    }

    public function __invoke(ChatWithAssistantRequest $request, int $organizerId): StreamedResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $dto = new ChatWithAssistantDTO(
            user: $this->getAuthenticatedUser(),
            accountId: $this->getAuthenticatedAccountId(),
            organizerId: $organizerId,
            messages: array_map(
                static fn(array $m): AssistantMessageDTO => new AssistantMessageDTO(
                    role: $m['role'],
                    content: $m['content'],
                    entities: $m['entities'] ?? [],
                ),
                $request->validated('messages'),
            ),
            focusedEventId: $request->validated('context.event_id'),
            attachmentId: $request->validated('context.attachment_id'),
        );

        return new StreamedResponse(function () use ($dto): void {
            try {
                $reply = $this->handler->handle($dto, $this->emit(...));
            } catch (AssistantDisabledException) {
                $this->emit('error', ['message' => __('The assistant is not enabled.'), 'status' => ResponseCodes::HTTP_NOT_FOUND]);
                return;
            } catch (OrganizerNotFoundException) {
                $this->emit('error', ['message' => __('Organizer not found.'), 'status' => ResponseCodes::HTTP_NOT_FOUND]);
                return;
            } catch (AssistantBudgetExceededException $e) {
                $this->emit('error', ['message' => $e->getMessage(), 'status' => ResponseCodes::HTTP_TOO_MANY_REQUESTS]);
                return;
            } catch (AssistantUnavailableException $e) {
                $this->emit('error', ['message' => $e->getMessage(), 'status' => ResponseCodes::HTTP_SERVICE_UNAVAILABLE]);
                return;
            } catch (Throwable $e) {
                report($e);
                $this->emit('error', ['message' => __('The assistant is temporarily unavailable.'), 'status' => ResponseCodes::HTTP_INTERNAL_SERVER_ERROR]);
                return;
            }

            $this->emit('done', [
                'reply' => $reply->reply,
                'tool_calls' => array_map(
                    static fn(AssistantToolCallDTO $call): array => ['name' => $call->name, 'arguments' => $call->arguments],
                    $reply->toolCalls,
                ),
                'entities' => $reply->entities,
                'input_tokens' => $reply->inputTokens,
                'output_tokens' => $reply->outputTokens,
            ]);
        }, ResponseCodes::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $event, array $data): void
    {
        echo sprintf(
            "event: %s\ndata: %s\n\n",
            $event,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
