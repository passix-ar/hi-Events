<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Http\Requests;

use HiEvents\Assistant\Domain\AssistantEntityLedger;
use HiEvents\Assistant\Handlers\DTO\AssistantMessageDTO;
use HiEvents\Http\Request\BaseRequest;

class ChatWithAssistantRequest extends BaseRequest
{
    public function rules(): array
    {
        $maxLength = (int)config('assistant.max_message_length', 4000);

        return [
            // The window the model sees is trimmed in the handler (max_history_messages);
            // this only bounds abuse, so a client one version behind never gets refused.
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.role' => ['required', 'string', 'in:' . AssistantMessageDTO::ROLE_USER . ',' . AssistantMessageDTO::ROLE_ASSISTANT],
            'messages.*.content' => ['required', 'string', 'max:' . $maxLength],
            'messages.*.entities' => ['nullable', 'array', 'max:' . AssistantEntityLedger::MAX_ENTRIES],
            'messages.*.entities.*.type' => ['required', 'string', 'in:' . AssistantEntityLedger::TYPE_EVENT . ',' . AssistantEntityLedger::TYPE_TICKET],
            'messages.*.entities.*.id' => ['required', 'integer', 'min:1'],
            'messages.*.entities.*.label' => ['required', 'string', 'max:' . AssistantEntityLedger::MAX_LABEL_LENGTH],
            'context' => ['nullable', 'array'],
            'context.event_id' => ['nullable', 'integer', 'min:1'],
            'context.attachment_id' => ['nullable', 'uuid'],
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
