import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, SeatingArrangement} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useReorderSeatingSections = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, sections}: { eventId: IdParam, sections: SeatingArrangement[] }) =>
            seatingClient.reorder(eventId, sections),

        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]});
        }
    });
}
