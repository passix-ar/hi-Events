import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {seatingClient} from "../api/seating.client.ts";

export const GET_SEATING_SECTION_QUERY_KEY = 'getSeatingSection';

export const useGetSeatingSection = (eventId: IdParam, seatingSectionId: IdParam) => {
    return useQuery({
        queryKey: [GET_SEATING_SECTION_QUERY_KEY, eventId, seatingSectionId],

        queryFn: async () => {
            const {data} = await seatingClient.get(eventId, seatingSectionId);
            return data;
        },

        enabled: !!seatingSectionId,
    });
};
