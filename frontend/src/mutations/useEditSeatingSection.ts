import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, SeatingSectionRequest} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {GET_SEATING_SECTION_QUERY_KEY} from "../queries/useGetSeatingSection.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useEditSeatingSection = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({seatingSectionData, eventId, seatingSectionId}: {
            eventId: IdParam,
            seatingSectionId: IdParam,
            seatingSectionData: SeatingSectionRequest,
        }) => seatingClient.update(eventId, seatingSectionId, seatingSectionData),

        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]});
            queryClient.invalidateQueries({queryKey: [GET_SEATING_SECTION_QUERY_KEY, variables.eventId, variables.seatingSectionId]});
        }
    });
}
