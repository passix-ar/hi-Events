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
     * The minimums rule out images too small to render and nothing else. Shape is guidance,
     * not a gate: a square minimum would reject the wide artwork organisers actually have,
     * and Passline itself accepts banners as small as 725x300.
     */
    public function test_minimums_do_not_impose_a_shape(): void
    {
        $this->assertSame([400, 200], ImageType::getMinimumDimensionsMap(ImageType::EVENT_COVER));
        $this->assertSame([700, 250], ImageType::getMinimumDimensionsMap(ImageType::EVENT_BANNER));

        // Real uploads that must get through: a square Instagram flyer, a portrait one,
        // a wide festival banner and an ordinary landscape photo — in either slot.
        foreach ([[1080, 1080], [1080, 1350], [1568, 385], [727, 521]] as [$width, $height]) {
            foreach ([ImageType::EVENT_COVER, ImageType::EVENT_BANNER] as $type) {
                [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($type);

                $this->assertTrue(
                    $width >= $minWidth && $height >= $minHeight,
                    sprintf('%dx%d should be accepted as %s', $width, $height, $type->name),
                );
            }
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
