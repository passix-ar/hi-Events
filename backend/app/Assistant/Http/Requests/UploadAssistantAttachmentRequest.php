<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Requests;

use HiEvents\Http\Request\BaseRequest;

class UploadAssistantAttachmentRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'mimes:jpeg,png,jpg,webp',
                // Anthropic caps a single image at 5 MB; the event cover itself allows 8.
                'max:5120',
                // Same floor as ImageType::EVENT_COVER so the flyer can become the cover later.
                'dimensions:min_width=400,min_height=200,max_width=4000,max_height=4000',
            ],
        ];
    }
}
