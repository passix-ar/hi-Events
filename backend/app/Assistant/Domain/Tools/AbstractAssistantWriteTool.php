<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

/**
 * Marker for the tools that change data. Three rules hold for every one of them
 * and are the reason writes are safe to expose to a model at all:
 *
 *  1. Nothing they create is public. Events are born DRAFT, tickets are refused
 *     on an event that is no longer a draft, and only a human publishes from the
 *     panel - so the worst outcome is a draft to delete, never something on sale.
 *  2. They do nothing until `confirm` is true. Without it they return a preview
 *     of exactly what they would create, which is what the organizer gets asked
 *     about before anything happens.
 *  3. They are idempotent on the obvious duplicate: asking twice for the same
 *     event or the same ticket returns the first one instead of making a second.
 *
 * The registry drops all of them when assistant.writes_enabled is false.
 */
abstract class AbstractAssistantWriteTool extends AbstractAssistantTool
{
    protected function preview(array $payload): string
    {
        return $this->toJson([
            'status' => 'needs_confirmation',
            'would_create' => $payload,
            'hint' => 'Show this to the organizer in their own words and ask for confirmation. '
                . 'Call again with confirm=true only after they agree.',
        ]);
    }

    /**
     * The double check for changes to something that already exists. A draft
     * takes a plain confirmation; a published event is selling, so the organizer
     * has to type the exact word. Returns the JSON to send back when the check
     * fails, null when the write may proceed.
     */
    protected function doubleCheck(bool $eventIsLive, ?bool $confirm, ?string $phrase, string $word): ?string
    {
        if ($confirm !== true) {
            return null; // the caller shows the preview
        }

        if ($eventIsLive && $phrase !== $word) {
            return $this->toJson([
                'error' => 'confirmation_phrase_required',
                'details' => sprintf('This event is published: ask the organizer to reply with the exact word %s.', $word),
            ]);
        }

        return null;
    }

    protected function logWrite(string $action, array $context): void
    {
        $this->logger->info('assistant.write.' . $action, array_merge([
            'user_id' => $this->context->user->getId(),
            'account_id' => $this->context->accountId,
            'organizer_id' => $this->context->organizerId,
        ], $context));
    }
}
