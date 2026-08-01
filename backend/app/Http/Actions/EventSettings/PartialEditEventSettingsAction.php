<?php

namespace HiEvents\Http\Actions\EventSettings;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\EventSettings\UpdateEventSettingsRequest;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class PartialEditEventSettingsAction extends BaseAction
{
    public function __construct(
        private readonly PartialUpdateEventSettingsHandler $partialUpdateEventSettingsHandler
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(UpdateEventSettingsRequest $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $event = $this->partialUpdateEventSettingsHandler->handle(
                PartialUpdateEventSettingsDTO::fromArray([
                    'settings' => $request->validated(),
                    'event_id' => $eventId,
                    'account_id' => $this->getAuthenticatedAccountId(),
                ]),
            );
        } catch (ResourceConflictException $e) {
            throw ValidationException::withMessages([
                'payment_providers' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(EventSettingsResource::class, $event);
    }
}
