<?php

namespace HiEvents\Http\Request\SeatingSection;

use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Http\Request\BaseRequest;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;

class UpsertSeatingSectionRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => RulesHelper::REQUIRED_STRING,
            'product_id' => ['required', 'integer'],
            'row_count' => ['required', 'integer', 'min:1', 'max:100'],
            'seats_per_row' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(SeatingSectionStatus::valuesArray())],
            'disabled_seats' => ['sometimes', 'nullable', 'array'],
            'disabled_seats.*' => ['string', 'max:10'],
            'aisle_positions' => ['sometimes', 'nullable', 'array'],
            'aisle_positions.*' => ['integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => __('Please select a product.'),
        ];
    }
}
