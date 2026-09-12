<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Actions;

use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use HiEvents\Assistant\Exceptions\AssistantDisabledException;
use HiEvents\Assistant\Exceptions\AssistantUnavailableException;
use HiEvents\Assistant\Handlers\ChatWithAssistantHandler;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Assistant\Handlers\DTO\ChatWithAssistantDTO;
use HiEvents\Assistant\Http\Requests\ChatWithAssistantRequest;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use Illuminate\Http\JsonResponse;

class ChatWithAssistantAction extends BaseAction
{
    public function __construct(
        private readonly ChatWithAssistantHandler $handler,
    )
    {
    }

    public function __invoke(ChatWithAssistantRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $reply = $this->handler->handle(new ChatWithAssistantDTO(
                user: $this->getAuthenticatedUser(),
                accountId: $this->getAuthenticatedAccountId(),
                organizerId: $organizerId,
                messages: array_map(
                    static fn(array $m): AssistantMessageDTO => new AssistantMessageDTO(
                        role: $m['role'],
                        content: $m['content'],
                    ),
                    $request->validated('messages'),
                ),
            ));
        } catch (AssistantDisabledException) {
            return $this->errorResponse(__('The assistant is not enabled.'), ResponseCodes::HTTP_NOT_FOUND);
        } catch (OrganizerNotFoundException) {
            return $this->errorResponse(__('Organizer not found.'), ResponseCodes::HTTP_NOT_FOUND);
        } catch (AssistantBudgetExceededException $e) {
            return $this->errorResponse($e->getMessage(), ResponseCodes::HTTP_TOO_MANY_REQUESTS);
        } catch (AssistantUnavailableException $e) {
            return $this->errorResponse($e->getMessage(), ResponseCodes::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->jsonResponse(
            data: [
                'reply' => $reply->reply,
                'tool_calls' => array_map(
                    static fn($call): array => ['name' => $call->name, 'arguments' => $call->arguments],
                    $reply->toolCalls,
                ),
            ],
            wrapInData: true,
        );
    }
}
