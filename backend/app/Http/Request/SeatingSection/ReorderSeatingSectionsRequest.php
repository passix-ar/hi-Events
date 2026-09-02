<?php

namespace HiEvents\Http\Request\SeatingSection;

use HiEvents\Http\Request\BaseRequest;

class ReorderSeatingSectionsRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct'],
        ];
    }
}
