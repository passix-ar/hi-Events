<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Validators\Rules\ImageRatioRangeRule;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Exercises the rule against real image files, because the bug it replaces was invisible
 * from the outside: Laravel's `dimensions:ratio=2.4` looked like it asked for 2.4:1 and
 * actually asked for it to within a single pixel.
 */
class ImageRatioRangeRuleTest extends TestCase
{
    private function passes(int $width, int $height, float $min = 1.25, float $max = 3.2): bool
    {
        return Validator::make(
            ['image' => File::image('banner.jpg', $width, $height)],
            ['image' => [new ImageRatioRangeRule($min, $max)]],
        )->passes();
    }

    public function test_it_accepts_a_hand_crop_that_misses_the_recommended_size_by_a_pixel(): void
    {
        $this->assertTrue($this->passes(1920, 800));
        $this->assertTrue($this->passes(1920, 801));
        $this->assertTrue($this->passes(1920, 799));
    }

    /**
     * Every size a stock photo site offers is 16:9. Refusing them all was the single
     * biggest source of rejections.
     */
    public function test_it_accepts_sixteen_by_nine(): void
    {
        $this->assertTrue($this->passes(1920, 1080));
        $this->assertTrue($this->passes(1280, 720));
        $this->assertTrue($this->passes(4000, 2250));
    }

    public function test_it_accepts_the_banners_already_in_production(): void
    {
        $this->assertTrue($this->passes(1600, 800));
        $this->assertTrue($this->passes(3780, 1890));
        $this->assertTrue($this->passes(1204, 908));
    }

    public function test_it_rejects_what_the_crop_would_destroy(): void
    {
        $this->assertFalse($this->passes(1080, 1080));
        $this->assertFalse($this->passes(1080, 1350));
        $this->assertFalse($this->passes(4000, 800, 1.25, 3.2));
    }

    /**
     * Dimensions the rule cannot read are left to the `image` and `dimensions` rules, so a
     * broken upload reports one error instead of two.
     */
    public function test_it_stays_quiet_on_values_that_are_not_uploaded_images(): void
    {
        $validator = Validator::make(
            ['image' => 'not-a-file'],
            ['image' => [new ImageRatioRangeRule(1.25, 3.2)]],
        );

        $this->assertTrue($validator->passes());
    }
}
