<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Exceptions\AssistantToolArgumentException;
use HiEvents\Assistant\Exceptions\AssistantToolAuthorizationException;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Tool;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every assistant tool extends this. It guarantees three things:
 *
 *  1. `organizer_id` / `account_id` come from the request context, never from the model.
 *  2. Any `event_id` the model picks goes through the same IsAuthorizedService the HTTP
 *     actions use, plus a stricter check that the event belongs to this organizer.
 *  3. Exceptions never leak internals to the model: it sees an opaque error code.
 */
abstract class AbstractAssistantTool extends Tool
{
    public const MAX_DATE_RANGE_DAYS = 370;

    public function __construct(
        protected readonly AssistantContext         $context,
        private readonly IsAuthorizedService        $isAuthorizedService,
        private readonly EventRepositoryInterface   $eventRepository,
        protected readonly LoggerInterface          $logger,
    )
    {
        parent::__construct();

        $this->failed(fn(Throwable $e, array $args): string => $this->mapFailure($e, $args));
        $this->configure();
        $this->using($this);
    }

    /**
     * Set name, description and parameters with the fluent API.
     */
    abstract protected function configure(): void;

    /**
     * @throws AssistantToolAuthorizationException
     */
    protected function authorizeEvent(int $eventId): EventDomainObject
    {
        try {
            $this->isAuthorizedService->isActionAuthorized(
                entityId: $eventId,
                entityType: EventDomainObject::class,
                authUser: $this->context->user,
                authAccountId: $this->context->accountId,
                minimumRole: Role::ORGANIZER,
            );
        } catch (UnauthorizedException $e) {
            throw new AssistantToolAuthorizationException(
                sprintf('event %d is not accessible from account %d', $eventId, $this->context->accountId),
                previous: $e,
            );
        } catch (ModelNotFoundException $e) {
            throw new AssistantToolAuthorizationException(
                sprintf('event %d does not exist', $eventId),
                previous: $e,
            );
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([
            'id' => $eventId,
            'account_id' => $this->context->accountId,
            'organizer_id' => $this->context->organizerId,
        ]);

        if ($event === null) {
            throw new AssistantToolAuthorizationException(
                sprintf('event %d does not belong to organizer %d', $eventId, $this->context->organizerId),
            );
        }

        return $event;
    }

    /**
     * @throws AssistantToolArgumentException
     */
    protected function validateArguments(array $arguments, array $rules): array
    {
        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new AssistantToolArgumentException(
                implode(' ', $validator->errors()->all()),
            );
        }

        return $validator->validated();
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     * @throws AssistantToolArgumentException
     */
    protected function resolveDateRange(?string $startDate, ?string $endDate): array
    {
        if ($startDate === null && $endDate === null) {
            return [null, null];
        }

        $timezone = $this->context->timezone;
        $end = $endDate ? Carbon::parse($endDate, $timezone)->endOfDay() : Carbon::now($timezone)->endOfDay();
        $start = $startDate ? Carbon::parse($startDate, $timezone)->startOfDay() : $end->copy()->subDays(30)->startOfDay();

        if ($start->greaterThan($end)) {
            throw new AssistantToolArgumentException('start_date must be before end_date.');
        }

        if ($start->diffInDays($end) > self::MAX_DATE_RANGE_DAYS) {
            throw new AssistantToolArgumentException(
                sprintf('Date range must be at most %d days.', self::MAX_DATE_RANGE_DAYS),
            );
        }

        return [$start, $end];
    }

    protected function toJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Free text written by third parties (buyers, event titles) is data, not
     * instructions. Trimming keeps a hostile string from dominating the context.
     */
    protected function clip(?string $text, int $max = 80): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    protected function money(float|int|string|null $amount): float
    {
        return round((float)($amount ?? 0), 2);
    }

    private function mapFailure(Throwable $e, array $arguments): string
    {
        if ($e instanceof AssistantToolAuthorizationException) {
            $this->logger->warning('assistant.tool.unauthorized', [
                'tool' => $this->name(),
                'user_id' => $this->context->user->getId(),
                'account_id' => $this->context->accountId,
                'organizer_id' => $this->context->organizerId,
                'arguments' => $arguments,
                'reason' => $e->getMessage(),
            ]);

            return $this->toJson(['error' => 'event_not_found']);
        }

        if ($e instanceof AssistantToolArgumentException) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => $e->getMessage()]);
        }

        // Wrong parameter names/types from the model: Prism's default message lists the
        // expected schema, which is exactly what the model needs to retry, and nothing else.
        if ($this->classifyToolError($e) === 'validation') {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => $this->getDefaultFailedMessage($e, $arguments)]);
        }

        $this->logger->error('assistant.tool.failed', [
            'tool' => $this->name(),
            'organizer_id' => $this->context->organizerId,
            'exception' => $e,
        ]);

        return $this->toJson(['error' => 'tool_failed']);
    }
}
