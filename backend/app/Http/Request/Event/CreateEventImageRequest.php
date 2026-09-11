<?php

namespace HiEvents\Http\Request\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Validators\Rules\ImageRatioRangeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEventImageRequest extends FormRequest
{
    public function rules(): array
    {
        $imageType = $this->resolveImageType();
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($imageType);
        $ratioRange = ImageType::getAllowedRatioRange($imageType);

        $rules = [
            'required',
            'image',
            'max:8192', //8mb
            'dimensions:min_width=' . $minWidth . ',min_height=' . $minHeight . ',max_width=4000,max_height=4000',
            'mimes:jpeg,png,jpg,webp',
        ];

        if ($ratioRange !== null) {
            $rules[] = new ImageRatioRangeRule($ratioRange[0], $ratioRange[1]);
        }

        return [
            'image' => $rules,
            'type' => Rule::in(ImageType::eventImageTypes()),
        ];
    }

    public function messages(): array
    {
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($this->resolveImageType());

        return [
            'image.dimensions' => __('The image must be between :minWidth x :minHeight and :maxWidth x :maxHeight pixels.', [
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
                'maxWidth' => 4000,
                'maxHeight' => 4000,
            ]),
        ];
    }

    private function resolveImageType(): ImageType
    {
        return ImageType::tryFromName($this->input('type')) ?? ImageType::EVENT_COVER;
    }
}
