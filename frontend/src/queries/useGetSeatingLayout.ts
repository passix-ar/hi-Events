import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {seatingClient} from "../api/seating.client.ts";

export const GET_SEATING_LAYOUT_QUERY_KEY = 'getSeatingLayout';

export const useGetSeatingLayout = (eventId: IdParam) => {
    return useQuery({
        queryKey: [GET_SEATING_LAYOUT_QUERY_KEY, eventId],

        queryFn: async () => {
            const {data} = await seatingClient.layout(eventId);
            return data;
        },

        enabled: !!eventId,
    });
};
