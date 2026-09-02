<?php

namespace HiEvents\Http\Request\SeatingSection;

use HiEvents\DomainObjects\Enums\SeatingSectionPosition;
use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class ReorderSeatingSectionsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.id' => ['required', 'integer', 'distinct'],
            'sections.*.layout_position' => ['required', Rule::in(SeatingSectionPosition::valuesArray())],
        ];
    }
}
