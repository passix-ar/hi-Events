<?php

namespace HiEvents\Http\Request\Image;

use HiEvents\DomainObjects\Enums\ImageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateImageRequest extends FormRequest
{
    public function rules(): array
    {
        $imageType = ImageType::tryFromName($this->input('image_type')) ?? ImageType::GENERIC;

        [$minWidth, $minHeight] = ImageType::getMinimumDimensionsMap($imageType);
        $ratio = ImageType::getRequiredRatio($imageType);

        // El ratio se exige solo donde la superficie tiene una forma fija (el banner):
        // ahi cualquier otra proporcion termina recortada en los bordes, que es donde el
        // organizador pone los logos y las fechas. La regla `ratio` de Laravel tolera el
        // redondeo, asi que 1920x800, 2400x1000 y 1440x600 entran todos.
        $dimensions = 'dimensions:min_width=' . $minWidth . ',min_height=' . $minHeight
            . ',max_width=4000,max_height=4000'
            . ($ratio !== null ? ',ratio=' . $ratio : '');

        return [
            'image' => [
                'required',
                'image',
                'max:8192', //8mb
                $dimensions,
                'mimes:jpeg,png,jpg,webp',
            ],
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
        $ratio = ImageType::getRequiredRatio($imageType);

        // Un solo mensaje cubre las dos causas de fallo de `dimensions`, asi que cuando
        // hay forma exigida el texto la nombra: sin eso, quien sube un cuadrado lee que
        // le falta tamano y vuelve a intentar con el mismo recorte, mas grande.
        $dimensionsMessage = $ratio !== null
            ? __('The banner must be :ratio:1 — for example 1920x800 — and at least :minWidth x :minHeight pixels.', [
                'ratio' => $ratio,
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
            ])
            : __('The image must be at least :minWidth x :minHeight pixels.', [
                'minWidth' => $minWidth,
                'minHeight' => $minHeight,
            ]);

        return [
            'image.dimensions' => $dimensionsMessage,
            'entity_id.required_with' => __('The entity ID is required when type is provided.'),
            'image_type.required_with' => __('The type is required when entity ID is provided.'),
        ];
    }
}
