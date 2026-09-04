import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useDeleteSeatingSection = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, seatingSectionId}: {
            eventId: IdParam,
            seatingSectionId: IdParam,
        }) => seatingClient.delete(eventId, seatingSectionId),

        onSuccess: (_, variables) => queryClient
            .invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]})
    });
}
