import {publicApi} from "./public-client";
import {
    Attendee,
    CheckInList,
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam, PublicCheckIn,
    QueryFilters,
} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper";

export const publicCheckInClient = {
    getCheckInList: async (checkInListShortId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<CheckInList>>(`/check-in-lists/${checkInListShortId}`);
        return response.data;
    },
    getCheckInListAttendees: async (checkInListShortId: IdParam, pagination: QueryFilters) => {
        const response = await publicApi.get<GenericPaginatedResponse<Attendee>>(`/check-in-lists/${checkInListShortId}/attendees` + queryParamsHelper.buildQueryString(pagination));
        return response.data;
    },
    getCheckInListAttendee: async (checkInListShortId: IdParam, attendeePublicId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<Attendee>>(`/check-in-lists/${checkInListShortId}/attendees/${attendeePublicId}`);
        return response.data;
    },
    getCheckInListAttendeesPage: async (checkInListShortId: IdParam, page: number, perPage: number) => {
        // Explicit params: the generic query helper falls back to the page URL's own
        // query string, which must not filter the scanner's full attendee list.
        const response = await publicApi.get<GenericPaginatedResponse<Attendee>>(
            `/check-in-lists/${checkInListShortId}/attendees?page=${page}&per_page=${perPage}`
        );
        return response.data;
    },
    createCheckIns: async (
        checkInListShortId: IdParam,
        attendees: { public_id: string, action: 'check-in' | 'check-in-and-mark-order-as-paid' }[],
        timeoutMs: number,
    ) => {
        const response = await publicApi.post<GenericDataResponse<PublicCheckIn[]>>(
            `/check-in-lists/${checkInListShortId}/check-ins`,
            {attendees},
            {timeout: timeoutMs},
        );
        return response.data;
    },
    createCheckIn: async (checkInListShortId: IdParam, attendeePublicId: IdParam, action: 'check-in' | 'check-in-and-mark-order-as-paid') => {
        const response = await publicApi.post<GenericDataResponse<PublicCheckIn[]>>(`/check-in-lists/${checkInListShortId}/check-ins`, {
            "attendees": [
                {
                    "public_id": attendeePublicId,
                    "action": action
                }
            ]
        });
        return response.data;
    },
    deleteCheckIn: async (checkInListShortId: IdParam, checkInShortId: IdParam) => {
        const response = await publicApi.delete<GenericDataResponse<PublicCheckIn>>(`/check-in-lists/${checkInListShortId}/check-ins/${checkInShortId}`);
        return response.data;
    },
};
