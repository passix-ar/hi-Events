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
    private function passes(int $width, int $height, float $min = 2.5, float $max = 3.5): bool
    {
        return Validator::make(
            ['image' => File::image('banner.jpg', $width, $height)],
            ['image' => [new ImageRatioRangeRule($min, $max)]],
        )->passes();
    }

    public function test_it_accepts_a_hand_crop_that_misses_the_recommended_size_by_a_pixel(): void
    {
        $this->assertTrue($this->passes(1920, 640));
        $this->assertTrue($this->passes(1920, 641));
        $this->assertTrue($this->passes(1920, 639));
    }

    public function test_it_accepts_the_edges_of_the_range(): void
    {
        $this->assertTrue($this->passes(1920, 768));  // 2.5:1
        $this->assertTrue($this->passes(2100, 600));  // 3.5:1
        $this->assertTrue($this->passes(1500, 500));  // an X/Twitter header
    }

    /**
     * The shapes organisers reach for first — a Facebook event cover, a stock photo, the
     * 2.4:1 we used to ask for — all lose a fifth or more of their height to the strip.
     */
    public function test_it_rejects_what_the_crop_would_visibly_damage(): void
    {
        $this->assertFalse($this->passes(1920, 800));
        $this->assertFalse($this->passes(1600, 800));
        $this->assertFalse($this->passes(1921, 982));
        $this->assertFalse($this->passes(1920, 1080));
        $this->assertFalse($this->passes(1080, 1080));
        $this->assertFalse($this->passes(1080, 1350));
        $this->assertFalse($this->passes(4000, 800));
    }

    /**
     * Dimensions the rule cannot read are left to the `image` and `dimensions` rules, so a
     * broken upload reports one error instead of two.
     */
    public function test_it_stays_quiet_on_values_that_are_not_uploaded_images(): void
    {
        $validator = Validator::make(
            ['image' => 'not-a-file'],
            ['image' => [new ImageRatioRangeRule(2.5, 3.5)]],
        );

        $this->assertTrue($validator->passes());
    }
}
