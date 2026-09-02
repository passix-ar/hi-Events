import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useReorderSeatingSections = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, sectionIds}: { eventId: IdParam, sectionIds: IdParam[] }) =>
            seatingClient.reorder(eventId, sectionIds),

        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]});
        }
    });
}
