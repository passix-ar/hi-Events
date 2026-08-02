<?php

namespace HiEvents\Http\Actions\EventSettings;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EditEventSettingsAction extends BaseAction
{
    public function __construct(
        private readonly UpdateEventSettingsHandler $updateEventSettingsHandler
    )
    {
    }

    public function __invoke(UpdateEventSettingsRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $settings = array_merge(
            $request->validated(),
            [
                'event_id' => $eventId,
                'account_id' => $this->getAuthenticatedAccountId(),
            ],
        );

        try {
            $event = $this->updateEventSettingsHandler->handle(
                UpdateEventSettingsDTO::fromArray($settings),
            );
        } catch (ResourceConflictException $e) {
            throw ValidationException::withMessages([
                'payment_providers' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(EventSettingsResource::class, $event);
    }
}
