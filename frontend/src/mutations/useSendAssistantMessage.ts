import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {AssistantChatContext, AssistantChatMessage, assistantClient} from "../api/assistant.client.ts";

export const useSendAssistantMessage = () => {
    return useMutation({
        mutationFn: ({organizerId, messages, context}: {
            organizerId: IdParam,
            messages: AssistantChatMessage[],
            context?: AssistantChatContext,
        }) => assistantClient.chat(organizerId, messages, context),
    });
}
