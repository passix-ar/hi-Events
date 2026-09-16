import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {assistantClient} from "../api/assistant.client.ts";

export const useUploadAssistantAttachment = () => {
    return useMutation({
        mutationFn: ({organizerId, file}: {organizerId: IdParam, file: File}) =>
            assistantClient.uploadAttachment(organizerId, file),
    });
}
