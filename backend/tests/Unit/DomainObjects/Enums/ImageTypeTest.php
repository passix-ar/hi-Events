<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
    public function test_event_banner_is_an_event_image(): void
    {
        $this->assertContains(ImageType::EVENT_BANNER, ImageType::eventImageTypes());
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_BANNER->getEntityType());
    }

    /**
     * The minimums rule out images too small to render and nothing else — not even for the
     * banner, whose shape is enforced separately (see the ratio test below). Keeping the two
     * concerns apart is what lets the cover slot stay shapeless: a square minimum there would
     * reject the Instagram flyer that is often an organiser's only artwork.
     */
    public function test_minimums_do_not_impose_a_shape(): void
    {
        $this->assertSame([400, 200], ImageType::getMinimumDimensionsMap(ImageType::EVENT_COVER));
        $this->assertSame([1500, 500], ImageType::getMinimumDimensionsMap(ImageType::EVENT_BANNER));

        // Real uploads that must clear the cover minimums: a square Instagram flyer, a
        // portrait one, a wide festival banner and an ordinary landscape photo.
        [$coverMinWidth, $coverMinHeight] = ImageType::getMinimumDimensionsMap(ImageType::EVENT_COVER);

        foreach ([[1080, 1080], [1080, 1350], [1568, 385], [727, 521]] as [$width, $height]) {
            $this->assertTrue(
                $width >= $coverMinWidth && $height >= $coverMinHeight,
                sprintf('%dx%d should clear the cover minimums', $width, $height),
            );
        }
    }

    /**
     * The smallest accepted banner has to be a shape the rule accepts. It used to be
     * 700x250 against an exact-2.4 rule: the very size the error message asked for could
     * not be uploaded. 1500x500 is 3:1 — an X/Twitter header exported at 1x — and it also
     * keeps the banner from being stretched past legibility on a 1920 screen.
     */
    public function test_the_banner_minimum_is_itself_a_valid_banner(): void
    {
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap(ImageType::EVENT_BANNER);
        [$minRatio, $maxRatio] = ImageType::getAllowedRatioRange(ImageType::EVENT_BANNER);

        $ratio = $minWidth / $minHeight;

        $this->assertGreaterThanOrEqual($minRatio, $ratio);
        $this->assertLessThanOrEqual($maxRatio, $ratio);
    }

    /**
     * The banner is the only slot with any shape requirement, and even there it is a range,
     * not a value: the strip crops what does not match, so the shapes worth refusing are
     * the ones the crop would visibly damage.
     */
    public function test_only_the_banner_constrains_its_shape(): void
    {
        $this->assertSame([2.5, 3.5], ImageType::getAllowedRatioRange(ImageType::EVENT_BANNER));
        $this->assertSame(3.0, ImageType::getRecommendedRatio(ImageType::EVENT_BANNER));

        foreach (ImageType::cases() as $case) {
            if ($case === ImageType::EVENT_BANNER) {
                continue;
            }

            $this->assertNull(
                ImageType::getAllowedRatioRange($case),
                $case->name . ' should not constrain its shape',
            );
        }
    }

    /**
     * The strip is 3:1 and crops everything else by the difference, so the range is drawn
     * where the crop stays small: 17% top and bottom at the floor, 14% off the sides at
     * the ceiling. A hand crop one pixel off the recommended size still has to get through.
     */
    public function test_the_banner_range_accepts_what_fits_the_strip(): void
    {
        $accepted = [
            [1920, 640],   // the recommended size
            [3840, 1280],  // a retina export of it
            [1500, 500],   // an X/Twitter header, the same shape at 1x
            [1920, 641],   // a hand crop one pixel off
            [1920, 720],   // 2.67:1, what All Access asks for — loses 11% and still reads
            [1920, 768],   // 2.5:1, the floor
            [2100, 600],   // 3.5:1, the ceiling
        ];

        foreach ($accepted as [$width, $height]) {
            $this->assertTrue(
                $this->shapeIsAccepted($width, $height),
                sprintf('%dx%d must be accepted', $width, $height),
            );
        }

        // What the crop would visibly damage. The first three were live in production and
        // are why the range tightened: the 1.96:1 lost 35% of its height on the homepage.
        $rejected = [
            [1921, 982],   // 1.96:1, half-cut artist photos and no venue logo
            [1600, 800],   // 2:1, a Facebook event cover — loses 33%
            [1204, 908],   // 4:3, a photo, not a banner
            [1920, 800],   // 2.4:1, the size we used to ask for — loses 20%
            [1920, 1080],  // 16:9, a stock photo
            [1080, 1080],  // square
            [1080, 1350],  // portrait
            [4000, 800],   // 5:1, too flat
        ];

        foreach ($rejected as [$width, $height]) {
            $this->assertFalse(
                $this->shapeIsAccepted($width, $height),
                sprintf('%dx%d must be rejected', $width, $height),
            );
        }
    }

    private function shapeIsAccepted(int $width, int $height): bool
    {
        [$minRatio, $maxRatio] = ImageType::getAllowedRatioRange(ImageType::EVENT_BANNER);
        $ratio = $width / $height;

        return $ratio >= $minRatio && $ratio <= $maxRatio;
    }

    public function test_every_type_has_its_own_minimum_dimensions(): void
    {
        foreach (ImageType::cases() as $case) {
            [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($case);

            $this->assertGreaterThan(0, $minWidth, $case->name . ' has no minimum width');
            $this->assertGreaterThan(0, $minHeight, $case->name . ' has no minimum height');
        }
    }

    public function test_try_from_name_resolves_known_names_and_rejects_the_rest(): void
    {
        $this->assertSame(ImageType::EVENT_BANNER, ImageType::tryFromName('EVENT_BANNER'));
        $this->assertNull(ImageType::tryFromName('NOT_A_TYPE'));
        $this->assertNull(ImageType::tryFromName(null));
        $this->assertNull(ImageType::tryFromName('event_banner'));
    }
}
