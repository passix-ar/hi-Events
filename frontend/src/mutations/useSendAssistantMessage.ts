import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {AssistantChatMessage, assistantClient} from "../api/assistant.client.ts";

export const useSendAssistantMessage = () => {
    return useMutation({
        mutationFn: ({organizerId, messages}: {
            organizerId: IdParam,
            messages: AssistantChatMessage[]
        }) => assistantClient.chat(organizerId, messages),
    });
}
