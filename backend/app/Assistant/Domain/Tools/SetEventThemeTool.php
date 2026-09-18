<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\HomepageBackgroundType;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * "Ponela rosa": the organizer picks the colours, by name or hex, and the page
 * follows. The companion of apply_flyer_palette for when the flyer's colours
 * are not what they want. Same write path (homepage_theme_settings), same
 * dark-first register: a background left unspecified is derived from the
 * accent so the page never ends up unreadable.
 */
class SetEventThemeTool extends AbstractAssistantWriteTool
{
    /** Names the organizer is likely to say, in Spanish and English, to a hex that reads on dark. */
    private const NAMED_COLOURS = [
        'rosa' => '#ff6fb5', 'pink' => '#ff6fb5', 'fucsia' => '#ff2d95', 'fuchsia' => '#ff2d95', 'magenta' => '#ff2d95',
        'rojo' => '#ff4d4d', 'red' => '#ff4d4d', 'bordo' => '#c0263b', 'bordó' => '#c0263b',
        'naranja' => '#ff8c42', 'orange' => '#ff8c42',
        'amarillo' => '#ffd43b', 'yellow' => '#ffd43b', 'dorado' => '#f2c94c', 'gold' => '#f2c94c',
        'lima' => '#d6ff3d', 'lime' => '#d6ff3d', 'verde' => '#4ade80', 'green' => '#4ade80',
        'turquesa' => '#2dd4bf', 'turquoise' => '#2dd4bf', 'teal' => '#2dd4bf',
        'celeste' => '#5ec8ff', 'cyan' => '#5ec8ff', 'azul' => '#4dabf7', 'blue' => '#4dabf7',
        'violeta' => '#a78bfa', 'purple' => '#a78bfa', 'lila' => '#c4b5fd', 'lilac' => '#c4b5fd', 'púrpura' => '#9b5cf6', 'morado' => '#9b5cf6',
        'blanco' => '#f5f5f5', 'white' => '#f5f5f5', 'negro' => '#0b0b0e', 'black' => '#0b0b0e',
        'gris' => '#9ca3af', 'gray' => '#9ca3af', 'grey' => '#9ca3af',
    ];

    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly ImageRepositoryInterface          $images,
        private readonly EventSettingsRepositoryInterface  $eventSettings,
        private readonly PartialUpdateEventSettingsHandler $updateSettings,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('set_event_theme')
            ->for('Sets the colours of the event page the way the organizer asks ("make it pink", "dark blue '
                . 'background", "light mode"). Accent is the colour of buttons and highlights; background is '
                . 'the page colour. Pass colours as hex (#ff6fb5) or a common name in Spanish/English (rosa, '
                . 'azul, verde...). Omit background to derive one from the accent. Call without confirm for a '
                . 'preview showing current and new values; confirm=true applies it. On a published event the '
                . 'organizer must reply MODIFICAR (pass it as confirmation_phrase). Use apply_flyer_palette '
                . 'instead when they want the colours of the flyer.')
            ->withNumberParameter('event_id', 'The event.')
            ->withStringParameter('accent', 'Accent colour: hex or colour name.', required: false)
            ->withStringParameter('background', 'Background colour: hex or colour name. Omit to derive from the accent.', required: false)
            ->withEnumParameter('mode', 'Light or dark page. Default: keep current (Passix is dark).', ['dark', 'light'], required: false)
            ->withEnumParameter('page_background', '"flyer" mirrors the cover image blurred behind the page; "color" uses the plain background colour.', ['flyer', 'color'], required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(
        int|float $event_id,
        ?string   $accent = null,
        ?string   $background = null,
        ?string   $mode = null,
        ?string   $page_background = null,
        ?bool     $confirm = null,
        ?string   $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'accent', 'background', 'mode', 'page_background'),
            [
                'event_id' => 'required|integer|min:1',
                'accent' => 'nullable|string|max:40',
                'background' => 'nullable|string|max:40',
                'mode' => 'nullable|in:dark,light',
                'page_background' => 'nullable|in:flyer,color',
            ],
        );

        if ($args['accent'] === null && $args['background'] === null && $args['mode'] === null && $args['page_background'] === null) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'Say what to change: accent, background, mode or page_background.']);
        }

        $event = $this->authorizeEvent((int)$args['event_id']);
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $current = is_array($settings?->getHomepageThemeSettings()) ? $settings->getHomepageThemeSettings() : [];

        $accentHex = $this->toHex($args['accent']);
        $backgroundHex = $this->toHex($args['background']);

        if ($args['accent'] !== null && $accentHex === null) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'accent must be a hex colour like #ff6fb5 or a common colour name.']);
        }
        if ($args['background'] !== null && $backgroundHex === null) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'background must be a hex colour like #0b0b0e or a common colour name.']);
        }

        $mode = $args['mode'] ?? ($current['mode'] ?? 'dark');
        $accentHex ??= $current['accent'] ?? '#d6ff3d';

        // A background nobody asked for follows the accent's hue, near black on
        // dark and near white on light, so the page keeps its contrast.
        if ($backgroundHex === null) {
            $backgroundHex = ($args['accent'] !== null || $args['mode'] !== null)
                ? $this->derivedBackground($accentHex, $mode)
                : ($current['background'] ?? '#0b0b0e');
        }

        $pageBackground = $args['page_background'];
        if ($pageBackground === 'flyer' && $this->cover($event) === null) {
            return $this->toJson(['error' => 'no_cover', 'details' => 'The event has no cover image, so the flyer cannot be the page background. Attach it first.']);
        }
        $backgroundType = match ($pageBackground) {
            'flyer' => HomepageBackgroundType::MIRROR_COVER_IMAGE->name,
            'color' => HomepageBackgroundType::COLOR->name,
            default => $current['background_type'] ?? HomepageBackgroundType::COLOR->name,
        };

        $new = ['accent' => $accentHex, 'background' => $backgroundHex, 'mode' => $mode, 'background_type' => $backgroundType];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                'event_id' => $event->getId(),
                'event_title' => $this->clip($event->getTitle()),
                'event_status' => $event->getStatus(),
                'current' => [
                    'accent' => $current['accent'] ?? null,
                    'background' => $current['background'] ?? null,
                    'mode' => $current['mode'] ?? null,
                ],
                'would_apply' => $new,
                'hint' => $isLive
                    ? 'This event is published: describe the change and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Describe the colours in words (not only hex) and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        if (($refusal = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, 'MODIFICAR')) !== null) {
            return $refusal;
        }

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: ['homepage_theme_settings' => array_merge($current, $new)],
        ));

        $this->logWrite('theme_set', ['event_id' => $event->getId(), 'theme' => $new]);

        return $this->toJson([
            'status' => 'applied',
            'event_id' => $event->getId(),
            'theme' => $new,
            'next_steps' => 'The page now uses these colours. Offer to tweak them again, or continue with the next setup step.',
        ]);
    }

    private function toHex(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if (preg_match('/^#?([0-9a-f]{6})$/', $value, $m)) {
            return '#' . $m[1];
        }
        if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        // "rosa claro", "azul oscuro": take the base colour, then lighten/darken.
        $words = preg_split('/\s+/', $value) ?: [];
        $base = null;
        foreach ($words as $word) {
            if (isset(self::NAMED_COLOURS[$word])) {
                $base = self::NAMED_COLOURS[$word];
                break;
            }
        }
        if ($base === null) {
            return null;
        }

        $shift = 0.0;
        foreach ($words as $word) {
            if (in_array($word, ['claro', 'clara', 'light', 'pastel', 'suave'], true)) {
                $shift = 0.15;
            } elseif (in_array($word, ['oscuro', 'oscura', 'dark', 'profundo', 'intenso'], true)) {
                $shift = -0.2;
            }
        }

        if ($shift === 0.0) {
            return $base;
        }

        [$h, $s, $l] = ColourMath::hexToHsl($base);

        return ColourMath::hslToHex($h, $s, min(0.92, max(0.12, $l + $shift)));
    }

    private function derivedBackground(string $accent, string $mode): string
    {
        [$h, $s] = ColourMath::hexToHsl($accent);

        return $mode === 'light'
            ? ColourMath::hslToHex($h, min($s, 0.35), 0.965)
            : ColourMath::hslToHex($h, min($s, 0.45), 0.07);
    }

    private function cover(EventDomainObject $event): mixed
    {
        return $this->images->findFirstWhere([
            'entity_id' => $event->getId(),
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_COVER->name,
        ]);
    }
}
