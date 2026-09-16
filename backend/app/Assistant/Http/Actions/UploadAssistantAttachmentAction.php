<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Actions;

use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\Assistant\Http\Requests\UploadAssistantAttachmentRequest;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use Illuminate\Http\JsonResponse;

/**
 * Receives an image dropped into the chat (a flyer) and parks it for the next
 * message. Nothing is attached to any event here: that happens through a tool,
 * after the model has read the image and the organizer has confirmed.
 */
class UploadAssistantAttachmentAction extends BaseAction
{
    public function __construct(
        private readonly AssistantAttachmentStore $attachments,
    )
    {
    }

    public function __invoke(UploadAssistantAttachmentRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        if (!config('assistant.enabled')) {
            return $this->errorResponse(__('The assistant is not enabled.'), ResponseCodes::HTTP_NOT_FOUND);
        }

        $attachment = $this->attachments->store(
            file: $request->file('image'),
            accountId: $this->getAuthenticatedAccountId(),
        );

        return $this->jsonResponse(
            data: [
                'id' => $attachment->id,
                'name' => $attachment->originalName,
                'size' => $attachment->sizeBytes,
            ],
            statusCode: ResponseCodes::HTTP_CREATED,
            wrapInData: true,
        );
    }
}
