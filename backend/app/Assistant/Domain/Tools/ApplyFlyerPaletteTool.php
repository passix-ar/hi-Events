<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\HomepageBackgroundType;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * Paints the event page with the flyer's colours. The cover's average colour
 * (computed at upload by ImageMetadataService) becomes the accent, lifted so it
 * reads on dark; the background is the same hue taken almost to black, so the
 * page stays in Passix's dark register but clearly belongs to this flyer.
 */
class ApplyFlyerPaletteTool extends AbstractAssistantWriteTool
{
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
            ->as('apply_flyer_palette')
            ->for('Themes the event page with the colours of its cover image (the flyer): accent and background '
                . 'derived from the image, dark mode, and the cover mirrored as the page background. Only for '
                . 'draft events that already have a cover. Preview first; confirm=true applies it.')
            ->withNumberParameter('event_id', 'The draft event.')
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed.', required: false);
    }

    public function __invoke(int|float $event_id, ?bool $confirm = null): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);

        if ($event->getStatus() !== EventStatus::DRAFT->name) {
            return $this->toJson(['error' => 'event_not_draft', 'details' => 'The page of a published event is themed from the panel.']);
        }

        $cover = $this->cover($event);

        if ($cover === null || $cover->getAvgColour() === null) {
            return $this->toJson([
                'error' => 'no_cover',
                'details' => 'The event has no cover image yet. Attach the flyer first (attach_flyer_to_event).',
            ]);
        }

        $palette = $this->paletteFrom($cover->getAvgColour());

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'from_colour' => $cover->getAvgColour(),
            'accent' => $palette['accent'],
            'background' => $palette['background'],
            'mode' => 'dark',
            'page_background' => 'the flyer, blurred',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $current = is_array($settings?->getHomepageThemeSettings()) ? $settings->getHomepageThemeSettings() : [];

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: [
                'homepage_theme_settings' => array_merge($current, [
                    'accent' => $palette['accent'],
                    'background' => $palette['background'],
                    'mode' => 'dark',
                    'background_type' => HomepageBackgroundType::MIRROR_COVER_IMAGE->name,
                ]),
            ],
        ));

        $this->logWrite('palette_applied', ['event_id' => $event->getId(), 'accent' => $palette['accent']]);

        return $this->toJson([
            'status' => 'applied',
            'event_id' => $event->getId(),
            'accent' => $palette['accent'],
            'background' => $palette['background'],
            'next_steps' => 'The event page now uses the flyer colours. The organizer can fine-tune them in the homepage designer.',
        ]);
    }

    private function cover(EventDomainObject $event): ?ImageDomainObject
    {
        /** @var ImageDomainObject|null $image */
        $image = $this->images->findFirstWhere([
            'entity_id' => $event->getId(),
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_COVER->name,
        ]);

        return $image;
    }

    /**
     * @return array{accent: string, background: string}
     */
    public function paletteFrom(string $hex): array
    {
        [$h, $s, $l] = $this->hexToHsl($hex);

        // An average colour is muddy by nature: push saturation up and put the
        // lightness where a button reads on a dark page. Greys stay lime.
        if ($s < 0.12) {
            return ['accent' => '#d6ff3d', 'background' => '#0b0b0e'];
        }

        $accent = $this->hslToHex($h, max($s, 0.75), 0.62);
        $background = $this->hslToHex($h, min($s, 0.45), 0.07);

        return ['accent' => $accent, 'background' => $background];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = array_map(static fn(string $c): float => hexdec($c) / 255, str_split($hex, 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    private function hslToHex(float $h, float $s, float $l): string
    {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = static function (float $t) use ($p, $q): float {
            if ($t < 0) { $t += 1; }
            if ($t > 1) { $t -= 1; }
            if ($t < 1 / 6) { return $p + ($q - $p) * 6 * $t; }
            if ($t < 1 / 2) { return $q; }
            if ($t < 2 / 3) { return $p + ($q - $p) * (2 / 3 - $t) * 6; }
            return $p;
        };

        return sprintf(
            '#%02x%02x%02x',
            (int)round($channel($h + 1 / 3) * 255),
            (int)round($channel($h) * 255),
            (int)round($channel($h - 1 / 3) * 255),
        );
    }
}
