<?php

namespace HiEvents\Http\Request\SeatingSection;

use HiEvents\Http\Request\BaseRequest;

class SaveSeatingLayoutRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'stage_x' => ['required', 'integer', 'between:-10000,10000'],
            'stage_y' => ['required', 'integer', 'between:-10000,10000'],
            'stage_visible' => ['required', 'boolean'],
            'sections' => ['present', 'array'],
            'sections.*.id' => ['required', 'integer', 'distinct'],
            'sections.*.position_x' => ['required', 'integer', 'between:-10000,10000'],
            'sections.*.position_y' => ['required', 'integer', 'between:-10000,10000'],
        ];
    }
}
