<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Domain\Tools\AbstractAssistantTool;
use HiEvents\Assistant\Domain\Tools\AbstractAssistantWriteTool;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\FindEventsTool;
use HiEvents\Assistant\Domain\Tools\GetEventStatsTool;
use HiEvents\Assistant\Domain\Tools\GetOrganizerStatsTool;
use HiEvents\Assistant\Domain\Tools\GetRecentOrdersTool;
use HiEvents\Assistant\Domain\Tools\GetTicketRankingTool;
use HiEvents\Assistant\Domain\Tools\SearchHelpDocsTool;
use Illuminate\Contracts\Container\Container;

/**
 * The closed list of what the model is allowed to call. Tools are built per
 * request with the context baked in, so a tool instance can never outlive or
 * escape the organizer it was created for.
 */
readonly class AssistantToolRegistry
{
    /** @var list<class-string<AbstractAssistantTool>> */
    private const TOOLS = [
        FindEventsTool::class,
        GetRecentOrdersTool::class,
        GetOrganizerStatsTool::class,
        GetEventStatsTool::class,
        GetTicketRankingTool::class,
        SearchHelpDocsTool::class,
        CreateDraftEventTool::class,
        CreateTicketTool::class,
    ];

    public function __construct(private Container $container)
    {
    }

    /**
     * @return list<AbstractAssistantTool>
     */
    public function forContext(AssistantContext $context): array
    {
        $tools = array_map(
            fn(string $tool): AbstractAssistantTool => $this->container->make($tool, ['context' => $context]),
            self::TOOLS,
        );

        if (!config('assistant.writes_enabled')) {
            $tools = array_filter(
                $tools,
                static fn(AbstractAssistantTool $tool): bool => !$tool instanceof AbstractAssistantWriteTool,
            );
        }

        return array_values($tools);
    }

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'find_events',
            'get_recent_orders',
            'get_organizer_stats',
            'get_event_stats',
            'get_ticket_ranking',
            'search_help_docs',
            'create_draft_event',
            'create_ticket',
        ];
    }
}
