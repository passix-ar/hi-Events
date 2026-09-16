<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\PanelRoutes;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class GetPanelRouteTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext         $context,
        IsAuthorizedService      $isAuthorizedService,
        EventRepositoryInterface $events,
        LoggerInterface          $logger,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_panel_route')
            ->for('Where something is done in the Passix panel: returns the exact page path, a short "how to", '
                . 'and a label. Use it whenever the organizer has to go somewhere (connect MercadoPago, publish, '
                . 'edit tickets, set up check-in…) and put the path in your answer as a markdown link with the '
                . 'label, e.g. [Conectar MercadoPago](/account/payment): the chat turns it into a button that '
                . 'opens that page. Pair it with search_help_docs when they also want the documentation.')
            ->withEnumParameter('destination', 'Which page.', PanelRoutes::names())
            ->withNumberParameter('event_id', 'Required for event pages.', required: false);
    }

    public function __invoke(string $destination, int|float|null $event_id = null): string
    {
        $args = $this->validateArguments(
            ['destination' => $destination, 'event_id' => $event_id],
            [
                'destination' => 'required|in:' . implode(',', PanelRoutes::names()),
                'event_id' => 'nullable|integer|min:1',
            ],
        );

        $route = PanelRoutes::DESTINATIONS[$args['destination']];
        $path = str_replace('{organizer}', (string)$this->context->organizerId, $route['path']);

        if ($route['needs_event']) {
            if (empty($args['event_id'])) {
                return $this->toJson(['error' => 'invalid_arguments', 'details' => 'This page belongs to an event: pass event_id (use find_events).']);
            }

            $event = $this->authorizeEvent((int)$args['event_id']);
            $path = str_replace('{event}', (string)$event->getId(), $path);
        }

        return $this->toJson([
            'label' => $route['label'],
            'path' => $path,
            'how_to' => $route['steps'],
            'markdown_link' => sprintf('[%s](%s)', $route['label'], $path),
        ]);
    }
}
