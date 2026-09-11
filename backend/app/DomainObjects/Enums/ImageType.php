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
     * The strip the banner fills on the Passix homepage is 2.4:1, so that is what we ask
     * for — but asking is not the same as demanding. This used to be a single 2.4, and
     * Laravel's `ratio` rule compares to within one pixel: 1920x801 was rejected, and so
     * was every size a stock photo site offers, which are all 16:9. Three of twelve
     * realistic sizes got through.
     *
     * The range is what the strip can absorb instead. A 16:9 banner loses 26% of its
     * height to the crop and still reads; the 4:3 already in production loses 45% and
     * still reads. A square loses 58% and does not. The floor sits just under 4:3 so a
     * photo passes and a flyer does not, and the designer warns with the real percentage
     * from 10% up — the gate only stops what is not a banner at all.
     *
     * Every other slot stays shapeless: see getMinimumDimensionsMap().
     *
     * @return array{0: float, 1: float}|null [min, max] ratio, or null if any shape goes
     */
    public static function getAllowedRatioRange(ImageType $imageType): ?array
    {
        return match ($imageType) {
            self::EVENT_BANNER => [1.25, 3.2],
            default => null,
        };
    }

    /**
     * The shape the designer asks for, and the one that fills the strip without cropping.
     */
    public static function getRecommendedRatio(ImageType $imageType): ?float
    {
        return match ($imageType) {
            self::EVENT_BANNER => 2.4,
            default => null,
        };
    }

    public static function getMinimumDimensionsMap(ImageType $imageType): array
    {
        // These only rule out images too small to render. Shape is constrained separately
        // and only for the banner (see getAllowedRatioRange); everywhere else it stays free,
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
