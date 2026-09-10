<?php

namespace HiEvents\DomainObjects\Enums;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use InvalidArgumentException;

enum ImageType
{
    use BaseEnum;

    case GENERIC;

    // Event images
    case EVENT_COVER;
    case EVENT_BANNER;
    case TICKET_LOGO;

    // Organizer images
    case ORGANIZER_LOGO;
    case ORGANIZER_COVER;

    public static function eventImageTypes(): array
    {
        return [
            self::EVENT_COVER,
            self::EVENT_BANNER,
            self::TICKET_LOGO,
        ];
    }

    public static function organizerImageTypes(): array
    {
        return [
            self::ORGANIZER_LOGO,
            self::ORGANIZER_COVER,
        ];
    }

    public static function genericImageTypes(): array
    {
        return [
            self::GENERIC,
        ];
    }

    /**
     * fromName() throws on an unknown name, which turns user input into a 500. This
     * resolves the same value but leaves the rejecting to the validator.
     */
    public static function tryFromName(?string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The banner is the only slot with a fixed shape, because it fills a strip of exactly
     * this proportion on the Passix homepage — anything else gets cropped, and the crop
     * eats the corners, where organisers put logos, dates and sponsors. 2.4 is 1920x800.
     *
     * Every other slot stays shapeless on purpose: see getMinimumDimensionsMap().
     */
    public static function getRequiredRatio(ImageType $imageType): ?float
    {
        return match ($imageType) {
            self::EVENT_BANNER => 2.4,
            default => null,
        };
    }

    public static function getMinimumDimensionsMap(ImageType $imageType): array
    {
        // These only rule out images too small to render. Shape is enforced separately and
        // only for the banner (see getRequiredRatio); for every other slot it stays free,
        // because requiring a square here would reject the wide artwork organisers have.
        $map = [
            self::GENERIC->name => [50, 50],
            self::EVENT_COVER->name => [400, 200],
            self::EVENT_BANNER->name => [1200, 500],
            self::TICKET_LOGO->name => [100, 100],
            self::ORGANIZER_LOGO->name => [100, 100],
            self::ORGANIZER_COVER->name => [600, 50],
        ];

        return $map[$imageType->name] ?? $map[self::GENERIC->name];
    }

    public function getEntityType(): string
    {
        if (in_array($this, self::eventImageTypes())) {
            return EventDomainObject::class;
        }

        if (in_array($this, self::organizerImageTypes())) {
            return OrganizerDomainObject::class;
        }

        if (in_array($this, self::genericImageTypes())) {
            return UserDomainObject::class;
        }

        throw new InvalidArgumentException('Invalid image type: ' . $this->name);
    }
}
