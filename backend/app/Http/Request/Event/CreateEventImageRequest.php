<?php

namespace HiEvents\Http\Request\Event;

use HiEvents\DomainObjects\Enums\ImageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEventImageRequest extends FormRequest
{
    public function rules(): array
    {
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($this->resolveImageType());

        return [
            'image' => [
                'required',
                'image',
                'max:8192', //8mb
                'dimensions:min_width=' . $minWidth . ',min_height=' . $minHeight . ',max_width=4000,max_height=4000',
                'mimes:jpeg,png,jpg,webp',
            ],
            'type' => Rule::in(ImageType::eventImageTypes()),
        ];
    }

    public function messages(): array
    {
        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($this->resolveImageType());

        return [
            'image.dimensions' => __('The image must be at least :minWidth x :minHeight pixels, and no more than 4000 x 4000 pixels.', [
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
            ]),
        ];
    }

    private function resolveImageType(): ImageType
    {
        return ImageType::tryFromName($this->input('type')) ?? ImageType::EVENT_COVER;
    }
}
