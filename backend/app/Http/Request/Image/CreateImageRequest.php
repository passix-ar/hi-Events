<?php

namespace HiEvents\Http\Request\Image;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Validators\Rules\ImageRatioRangeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateImageRequest extends FormRequest
{
    public function rules(): array
    {
        $imageType = ImageType::tryFromName($this->input('image_type')) ?? ImageType::GENERIC;

        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($imageType);
        $ratioRange = ImageType::getAllowedRatioRange($imageType);

        $rules = [
            'required',
            'image',
            'max:8192', //8mb
            'dimensions:min_width=' . $minWidth . ',min_height=' . $minHeight . ',max_width=4000,max_height=4000',
            'mimes:jpeg,png,jpg,webp',
        ];

        // La forma se valida aparte de `dimensions` y como rango, no como valor exacto:
        // `ratio=` compara con precision de un pixel, asi que rechazaba un 1920x801 y
        // tambien todas las medidas que ofrece un banco de fotos, que son 16:9.
        if ($ratioRange !== null) {
            $rules[] = new ImageRatioRangeRule($ratioRange[0], $ratioRange[1]);
        }

        return [
            'image' => $rules,
            'image_type' => [
                Rule::in(ImageType::valuesArray()),
                'required_with:entity_id'
            ],
            'entity_id' => ['integer', 'required_with:image_type'],
        ];
    }

    public function messages(): array
    {
        $imageType = ImageType::tryFromName($this->input('image_type')) ?? ImageType::GENERIC;

        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($imageType);

        // El mensaje nombra las dos cotas porque `dimensions` falla por ambas: quien baja
        // el "Original" de un banco de fotos se pasa del maximo, y leer solo el minimo lo
        // manda a buscar una imagen todavia mas grande.
        return [
            'image.dimensions' => __('The image must be between :minWidth x :minHeight and :maxWidth x :maxHeight pixels.', [
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
                'maxWidth' => 4000,
                'maxHeight' => 4000,
            ]),
            'entity_id.required_with' => __('The entity ID is required when type is provided.'),
            'image_type.required_with' => __('The type is required when entity ID is provided.'),
        ];
    }
}
