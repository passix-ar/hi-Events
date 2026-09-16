<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Images\CreateImageHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * Sets the image the organizer dropped into the chat as the event's cover.
 * The model never names a file: the only image it can attach is the one on
 * this turn's context, which the store already resolved within this account.
 */
class AttachFlyerToEventTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                          $context,
        IsAuthorizedService                       $isAuthorizedService,
        EventRepositoryInterface                  $events,
        LoggerInterface                           $logger,
        private readonly AssistantAttachmentStore $attachments,
        private readonly CreateImageHandler       $createImage,
        private readonly ImageRepositoryInterface  $images,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('attach_flyer_to_event')
            ->for('Uses the image the organizer attached to this message as the cover image of one of their '
                . 'draft events. Call it after create_draft_event when a flyer was attached. Preview without '
                . 'confirm first; confirm=true replaces any existing cover.')
            ->withNumberParameter('event_id', 'The draft event that gets the image.')
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed.', required: false);
    }

    private function hasCover(EventDomainObject $event): bool
    {
        return $this->images->findFirstWhere([
            'entity_id' => $event->getId(),
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_COVER->name,
        ]) !== null;
    }

    public function __invoke(int|float $event_id, ?bool $confirm = null): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);

        if ($this->context->attachment === null) {
            // A retry after the flyer was already used is not an error: the cover
            // is there, and the chain (palette next) must not derail on it.
            if ($this->hasCover($event)) {
                return $this->toJson([
                    'status' => 'already_exists',
                    'event_id' => $event->getId(),
                    'hint' => 'The event already has a cover image; nothing changed. Continue with the next step (apply_flyer_palette if the organizer wanted the colours).',
                ]);
            }

            return $this->toJson([
                'error' => 'no_attachment',
                'details' => 'No image was attached to this message. Ask the organizer to attach the flyer and try again.',
            ]);
        }

        if ($event->getStatus() !== EventStatus::DRAFT->name) {
            return $this->toJson([
                'error' => 'event_not_draft',
                'details' => 'This event is already published; its images are changed from the panel.',
            ]);
        }

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'image' => $this->context->attachment->originalName,
            'as' => 'event cover image',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        $upload = $this->attachments->asUploadedFile($this->context->attachment);

        try {
            $image = $this->createImage->handle(new CreateImageDTO(
                userId: $this->context->user->getId(),
                accountId: $this->context->accountId,
                image: $upload,
                imageType: ImageType::EVENT_COVER,
                entityId: $event->getId(),
            ));
        } finally {
            @unlink($upload->getRealPath());
        }

        // Consumed: later turns that still carry the id resolve to nothing.
        $this->attachments->delete($this->context->attachment);

        $this->logWrite('flyer_attached', [
            'event_id' => $event->getId(),
            'image_id' => $image->getId(),
        ]);

        return $this->toJson([
            'status' => 'created',
            'event_id' => $event->getId(),
            'image_id' => $image->getId(),
            'next_steps' => 'The flyer is now the event cover. The organizer can crop or replace it from the panel.',
        ]);
    }
}
