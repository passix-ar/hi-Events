import {api} from "./client";
import {publicApi} from "./public-client";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    QueryFilters,
    SeatingSection,
    SeatingSectionRequest,
} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";

export const seatingClient = {
    create: async (eventId: IdParam, seatingSection: SeatingSectionRequest) => {
        const response = await api.post<GenericDataResponse<SeatingSection>>(`events/${eventId}/seating-sections`, seatingSection);
        return response.data;
    },
    update: async (eventId: IdParam, seatingSectionId: IdParam, seatingSection: SeatingSectionRequest) => {
        const response = await api.put<GenericDataResponse<SeatingSection>>(`events/${eventId}/seating-sections/${seatingSectionId}`, seatingSection);
        return response.data;
    },
    all: async (eventId: IdParam, pagination: QueryFilters) => {
        const response = await api.get<GenericPaginatedResponse<SeatingSection>>(`events/${eventId}/seating-sections` + queryParamsHelper.buildQueryString(pagination));
        return response.data;
    },
    get: async (eventId: IdParam, seatingSectionId: IdParam) => {
        const response = await api.get<GenericDataResponse<SeatingSection>>(`events/${eventId}/seating-sections/${seatingSectionId}`);
        return response.data;
    },
    reorder: async (eventId: IdParam, sectionIds: IdParam[]) => {
        const response = await api.post<GenericDataResponse<SeatingSection[]>>(`events/${eventId}/seating-sections/reorder`, {section_ids: sectionIds});
        return response.data;
    },
    delete: async (eventId: IdParam, seatingSectionId: IdParam) => {
        const response = await api.delete<GenericDataResponse<SeatingSection>>(`events/${eventId}/seating-sections/${seatingSectionId}`);
        return response.data;
    },
}

export const seatingClientPublic = {
    sections: async (eventId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<SeatingSection[]>>(`events/${eventId}/seating-sections`);
        return response.data;
    },
}
