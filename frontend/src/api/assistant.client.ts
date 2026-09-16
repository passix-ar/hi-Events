import {api} from "./client";
import {AssistantReply, GenericDataResponse, IdParam} from "../types";

export interface AssistantChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

export interface AssistantChatContext {
    event_id?: IdParam;
    attachment_id?: string;
}

export interface AssistantAttachment {
    id: string;
    name: string;
    size: number;
}

export const assistantClient = {
    chat: async (organizerId: IdParam, messages: AssistantChatMessage[], context?: AssistantChatContext) => {
        const response = await api.post<GenericDataResponse<AssistantReply>>(
            'organizers/' + organizerId + '/assistant/chat',
            {messages, context}
        );
        return response.data;
    },

    uploadAttachment: async (organizerId: IdParam, file: File) => {
        const form = new FormData();
        form.append('image', file);

        const response = await api.post<GenericDataResponse<AssistantAttachment>>(
            'organizers/' + organizerId + '/assistant/attachments',
            form,
            {headers: {'Content-Type': 'multipart/form-data'}},
        );
        return response.data;
    },
};
