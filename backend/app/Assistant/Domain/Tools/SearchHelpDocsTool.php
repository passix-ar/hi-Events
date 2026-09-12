<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\HelpDocs\HelpDocsIndex;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class SearchHelpDocsTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext               $context,
        IsAuthorizedService            $isAuthorizedService,
        EventRepositoryInterface       $events,
        LoggerInterface                $logger,
        private readonly HelpDocsIndex $helpDocs,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('search_help_docs')
            ->for('Searches the Passix user documentation (docs.getpassix.com) and returns the matching sections '
                . 'with their public URL. Use it for any "how do I…", "where do I…", "what does X mean" or '
                . '"can Passix do X" question about using the platform: refunds, check-in, payouts, MercadoPago, '
                . 'seating, affiliates, emails, widget, API, transfers, fees. It knows nothing about this '
                . 'organizer\'s own numbers, which come from the other tools.')
            ->withStringParameter('query', 'Keywords describing what the user wants to do, in the user\'s own words.')
            ->withNumberParameter('limit', 'Maximum number of sections to return (1-6, default 4).', required: false);
    }

    public function __invoke(string $query, int|float|null $limit = null): string
    {
        $args = $this->validateArguments(
            ['query' => $query, 'limit' => $limit],
            [
                'query' => 'required|string|min:2|max:200',
                'limit' => 'nullable|integer|min:1|max:6',
            ],
        );

        $results = $this->helpDocs->search(
            query: $args['query'],
            limit: (int)($args['limit'] ?? 4),
        );

        if ($results === []) {
            return $this->toJson([
                'results' => [],
                'available_pages' => $this->helpDocs->pages(),
                'hint' => 'No section matched. Pick the closest page from available_pages and search again with its wording.',
            ]);
        }

        return $this->toJson(['results' => $results]);
    }
}
