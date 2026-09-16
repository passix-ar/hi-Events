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
     * The strip the banner fills on the Passix homepage is 3:1, edge to edge, and its
     * height is the image's: whatever is not 3:1 gets cropped, centred, by exactly the
     * difference. So the range is a statement of how much crop still looks like a banner.
     *
     * It used to be [1.25, 3.2] around a 2.4:1 strip, on the theory that the gate should
     * only stop what is not a banner at all and the designer's warning would do the fine
     * work. Production showed the warning does not: a 1.96:1 flyer went through, lost 35%
     * of its height, and the homepage showed half-cut artist photos and no venue logo.
     * A 2:1 Facebook event cover — the shape organisers actually have — loses 33%.
     *
     * The bounds are where the loss stays small. At 2.5:1 a banner loses 17% top and
     * bottom, at 3.5:1 it loses 14% of its sides; the designer flags anything from 5% up
     * with the real figure. A range rather than a single value because a hand crop that
     * lands on 1920x641 must not bounce.
     *
     * Every other slot stays shapeless: see getMinimumDimensionsMap().
     *
     * @return array{0: float, 1: float}|null [min, max] ratio, or null if any shape goes
     */
    public static function getAllowedRatioRange(ImageType $imageType): ?array
    {
        return match ($imageType) {
            self::EVENT_BANNER => [2.5, 3.5],
            default => null,
        };
    }

    /**
     * The shape the designer asks for, and the one that fills the strip without cropping.
     * 3:1 is also the X/Twitter header shape, so every design tool has it as a preset.
     */
    public static function getRecommendedRatio(ImageType $imageType): ?float
    {
        return match ($imageType) {
            self::EVENT_BANNER => 3.0,
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
            self::EVENT_BANNER->name => [1500, 500],
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
