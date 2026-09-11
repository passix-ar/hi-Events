<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Requests;

use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Http\Request\BaseRequest;

class ChatWithAssistantRequest extends BaseRequest
{
    public function rules(): array
    {
        $maxMessages = (int)config('assistant.max_history_messages', 20);
        $maxLength = (int)config('assistant.max_message_length', 4000);

        return [
            'messages' => ['required', 'array', 'min:1', 'max:' . $maxMessages],
            'messages.*.role' => ['required', 'string', 'in:' . AssistantMessageDTO::ROLE_USER . ',' . AssistantMessageDTO::ROLE_ASSISTANT],
            'messages.*.content' => ['required', 'string', 'max:' . $maxLength],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $messages = $this->input('messages', []);
            $last = end($messages);

            if (is_array($last) && ($last['role'] ?? null) !== AssistantMessageDTO::ROLE_USER) {
                $validator->errors()->add('messages', __('The last message must come from the user.'));
            }
        });
    }
}
