import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, SeatingSectionRequest} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useCreateSeatingSection = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({seatingSectionData, eventId}: {
            eventId: IdParam,
            seatingSectionData: SeatingSectionRequest,
        }) => seatingClient.create(eventId, seatingSectionData),

        onSuccess: (_, variables) => queryClient
            .invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]})
    });
}
