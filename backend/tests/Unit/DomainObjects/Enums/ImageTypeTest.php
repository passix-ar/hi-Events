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
        $this->assertSame([1200, 500], ImageType::getMinimumDimensionsMap(ImageType::EVENT_BANNER));

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
     * The banner minimum is a pair of the required shape on purpose. It used to be 700x250,
     * which is 2.8:1 — the exact size the error message asked for could not be uploaded,
     * because the ratio rule rejected it. 1200x500 is 2.4:1 and also keeps the banner from
     * being stretched past legibility: below that it visibly softens on a 1920 screen.
     */
    public function test_the_banner_minimum_is_itself_a_valid_banner(): void
    {
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap(ImageType::EVENT_BANNER);

        $this->assertEqualsWithDelta(
            ImageType::getRequiredRatio(ImageType::EVENT_BANNER),
            $minWidth / $minHeight,
            0.001,
            'the smallest accepted banner must itself pass the ratio rule',
        );
    }

    /**
     * The banner fills a 2.4:1 strip on the Passix homepage, so anything else gets cropped —
     * and the crop eats the corners, where logos, dates and sponsors live. It is the only
     * slot with a fixed shape; every other one would be rejecting artwork for no reason.
     */
    public function test_only_the_banner_requires_a_ratio(): void
    {
        $this->assertSame(2.4, ImageType::getRequiredRatio(ImageType::EVENT_BANNER));

        foreach (ImageType::cases() as $case) {
            if ($case === ImageType::EVENT_BANNER) {
                continue;
            }

            $this->assertNull(
                ImageType::getRequiredRatio($case),
                $case->name . ' should not impose a shape',
            );
        }
    }

    /**
     * The sizes the designer can hand over: the recommended one, a retina export and a
     * smaller cut. All three are the same shape, so all three have to be accepted — this
     * is why the rule is a ratio and not a fixed 1920x800.
     */
    public function test_the_banner_ratio_accepts_any_resolution_of_the_same_shape(): void
    {
        $ratio = ImageType::getRequiredRatio(ImageType::EVENT_BANNER);

        foreach ([[1920, 800], [3840, 1600], [1440, 600], [2400, 1000]] as [$width, $height]) {
            $this->assertEqualsWithDelta(
                $ratio,
                $width / $height,
                0.001,
                sprintf('%dx%d is the recommended shape and must be accepted', $width, $height),
            );
        }

        // And the ones that would get cropped: a square flyer, the old 3:1 advice, a 2:1.
        foreach ([[1080, 1080], [1920, 640], [1600, 800]] as [$width, $height]) {
            $this->assertNotEqualsWithDelta(
                $ratio,
                $width / $height,
                0.001,
                sprintf('%dx%d is off-shape and must be rejected', $width, $height),
            );
        }
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
