import {api} from "./client";
import {AssistantReply, GenericDataResponse, IdParam} from "../types";

export interface AssistantChatMessage {
    role: 'user' | 'assistant';
    content: string;
}

export const assistantClient = {
    chat: async (organizerId: IdParam, messages: AssistantChatMessage[]) => {
        const response = await api.post<GenericDataResponse<AssistantReply>>(
            'organizers/' + organizerId + '/assistant/chat',
            {messages}
        );
        return response.data;
    },
};
