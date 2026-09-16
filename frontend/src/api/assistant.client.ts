import {api} from "./client";
import {AssistantReply, GenericDataResponse, IdParam} from "../types";

export interface AssistantChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

export interface AssistantChatContext {
    event_id?: IdParam;
}

export const assistantClient = {
    chat: async (organizerId: IdParam, messages: AssistantChatMessage[], context?: AssistantChatContext) => {
        const response = await api.post<GenericDataResponse<AssistantReply>>(
            'organizers/' + organizerId + '/assistant/chat',
            {messages, context}
        );
        return response.data;
    },
};
