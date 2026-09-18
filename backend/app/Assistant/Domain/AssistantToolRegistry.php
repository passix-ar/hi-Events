<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Domain\Tools\AbstractAssistantTool;
use HiEvents\Assistant\Domain\Tools\AbstractAssistantWriteTool;
use HiEvents\Assistant\Domain\Tools\ApplyFlyerPaletteTool;
use HiEvents\Assistant\Domain\Tools\AttachFlyerToEventTool;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreatePromoCodeTool;
use HiEvents\Assistant\Domain\Tools\DeleteEventTool;
use HiEvents\Assistant\Domain\Tools\DeleteTicketTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\FindAttendeeTool;
use HiEvents\Assistant\Domain\Tools\FindEventsTool;
use HiEvents\Assistant\Domain\Tools\GetCheckInSummaryTool;
use HiEvents\Assistant\Domain\Tools\GetDoorStatusTool;
use HiEvents\Assistant\Domain\Tools\GetEventPromoKitTool;
use HiEvents\Assistant\Domain\Tools\GetEventSalesCurveTool;
use HiEvents\Assistant\Domain\Tools\GetEventSetupStatusTool;
use HiEvents\Assistant\Domain\Tools\GetEventStatsTool;
use HiEvents\Assistant\Domain\Tools\GetOrganizerStatsTool;
use HiEvents\Assistant\Domain\Tools\GetPanelRouteTool;
use HiEvents\Assistant\Domain\Tools\GetPromoCodesPerformanceTool;
use HiEvents\Assistant\Domain\Tools\GetRecentOrdersTool;
use HiEvents\Assistant\Domain\Tools\GetTicketRankingTool;
use HiEvents\Assistant\Domain\Tools\MessageBuyersTool;
use HiEvents\Assistant\Domain\Tools\PublishEventTool;
use HiEvents\Assistant\Domain\Tools\SetEventThemeTool;
use HiEvents\Assistant\Domain\Tools\SetOfflinePaymentTool;
use HiEvents\Assistant\Domain\Tools\GetSeatingSectionsTool;
use HiEvents\Assistant\Domain\Tools\CreateSeatingSectionTool;
use HiEvents\Assistant\Domain\Tools\DeleteSeatingSectionTool;
use HiEvents\Assistant\Domain\Tools\ReorderSeatingSectionsTool;
use HiEvents\Assistant\Domain\Tools\SetPlatformFeePayerTool;
use HiEvents\Assistant\Domain\Tools\SetCheckoutSettingsTool;
use HiEvents\Assistant\Domain\Tools\SetEventLocationTool;
use HiEvents\Assistant\Domain\Tools\SearchHelpDocsTool;
use HiEvents\Assistant\Domain\Tools\UpdateEventTool;
use HiEvents\Assistant\Domain\Tools\UpdateTicketTool;
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
        GetPromoCodesPerformanceTool::class,
        GetCheckInSummaryTool::class,
        SearchHelpDocsTool::class,
        GetPanelRouteTool::class,
        GetEventSetupStatusTool::class,
        GetEventPromoKitTool::class,
        GetEventSalesCurveTool::class,
        FindAttendeeTool::class,
        GetDoorStatusTool::class,
        CreateDraftEventTool::class,
        CreateTicketTool::class,
        AttachFlyerToEventTool::class,
        ApplyFlyerPaletteTool::class,
        SetEventThemeTool::class,
        SetOfflinePaymentTool::class,
        GetSeatingSectionsTool::class,
        CreateSeatingSectionTool::class,
        DeleteSeatingSectionTool::class,
        ReorderSeatingSectionsTool::class,
        SetPlatformFeePayerTool::class,
        SetCheckoutSettingsTool::class,
        SetEventLocationTool::class,
        PublishEventTool::class,
        CreatePromoCodeTool::class,
        MessageBuyersTool::class,
        UpdateEventTool::class,
        UpdateTicketTool::class,
        DeleteTicketTool::class,
        DeleteEventTool::class,
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
            'get_promo_codes_performance',
            'get_check_in_summary',
            'search_help_docs',
            'get_panel_route',
            'get_event_setup_status',
            'get_event_promo_kit',
            'get_event_sales_curve',
            'find_attendee',
            'get_door_status',
            'create_draft_event',
            'create_ticket',
            'attach_flyer_to_event',
            'apply_flyer_palette',
            'set_event_theme',
            'set_offline_payment',
            'get_seating_sections',
            'create_seating_section',
            'delete_seating_section',
            'reorder_seating_sections',
            'set_platform_fee_payer',
            'set_checkout_settings',
            'set_event_location',
            'publish_event',
            'create_promo_code',
            'message_buyers',
            'update_event',
            'update_ticket',
            'delete_ticket',
            'delete_event',
        ];
    }
}
