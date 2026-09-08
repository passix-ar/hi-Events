import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {seatingClientPublic} from "../api/seating.client.ts";

export const GET_EVENT_SEATING_SECTIONS_PUBLIC_QUERY_KEY = 'getEventSeatingSectionsPublic';

export const useGetSeatingSectionsPublic = (eventId: IdParam) => {
    return useQuery({
        queryKey: [GET_EVENT_SEATING_SECTIONS_PUBLIC_QUERY_KEY, eventId],

        queryFn: async () => {
            const {data} = await seatingClientPublic.sections(eventId);
            return data;
        },

        enabled: !!eventId,
    });
};
