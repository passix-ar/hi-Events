<?php

declare(strict_types=1);

namespace HiEvents\Validators\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Accepts an image whose proportion falls inside a range.
 *
 * Laravel's `dimensions:ratio=` takes a single value and compares it to within
 * 1/(longest side + 1) — about one pixel. A 1920x801 export was rejected by a rule
 * whose own error message suggested 1920x800, which is a dead end for anyone who
 * cropped by hand.
 *
 * A range says the useful thing instead: the homepage strip crops whatever does not
 * match it, so the only shapes worth refusing are the ones the crop would destroy.
 * Everything in between is allowed through and warned about in the designer.
 */
class ImageRatioRangeRule implements ValidationRule
{
    public function __construct(
        private readonly float $minRatio,
        private readonly float $maxRatio,
    )
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }

        $dimensions = @getimagesize($value->getRealPath());

        // Unreadable dimensions are not this rule's problem: the `image` and `dimensions`
        // rules already reject the file, and failing here too would only duplicate errors.
        if ($dimensions === false || empty($dimensions[1])) {
            return;
        }

        $ratio = $dimensions[0] / $dimensions[1];

        if ($ratio < $this->minRatio || $ratio > $this->maxRatio) {
            $fail(__('The image must be landscape. A tall or square image would be cropped beyond recognition in the featured strip — we recommend 1920x800.'));
        }
    }
}
