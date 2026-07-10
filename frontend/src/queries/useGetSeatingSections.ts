import {useQuery} from "@tanstack/react-query";
import {IdParam, QueryFilters} from "../types.ts";
import {seatingClient} from "../api/seating.client.ts";

export const GET_EVENT_SEATING_SECTIONS_QUERY_KEY = 'getEventSeatingSections';

export const useGetEventSeatingSections = (eventId: IdParam, pagination: QueryFilters) => {
    return useQuery({
        queryKey: [GET_EVENT_SEATING_SECTIONS_QUERY_KEY, eventId, pagination],

        queryFn: async () => {
            return await seatingClient.all(eventId, pagination);
        }
    });
};
