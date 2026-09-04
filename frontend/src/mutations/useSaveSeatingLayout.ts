import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam, SeatingLayoutRequest} from "../types.ts";
import {GET_EVENT_SEATING_SECTIONS_QUERY_KEY} from "../queries/useGetSeatingSections.ts";
import {GET_SEATING_LAYOUT_QUERY_KEY} from "../queries/useGetSeatingLayout.ts";
import {seatingClient} from "../api/seating.client.ts";

export const useSaveSeatingLayout = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, layout}: { eventId: IdParam, layout: SeatingLayoutRequest }) =>
            seatingClient.saveLayout(eventId, layout),

        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, variables.eventId]});
            queryClient.invalidateQueries({queryKey: [GET_SEATING_LAYOUT_QUERY_KEY, variables.eventId]});
        }
    });
}
