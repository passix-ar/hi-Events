import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const GET_MP_PREFERENCE_QUERY_KEY = 'getMercadoPagoPreference';

export const useCreateMercadoPagoPreference = (eventId: IdParam, orderShortId: IdParam) => {
    return useQuery({
        queryKey: [GET_MP_PREFERENCE_QUERY_KEY, eventId, orderShortId],
        queryFn: async () => {
            return await orderClientPublic.createMercadoPagoPreference(
                Number(eventId),
                String(orderShortId),
            );
        },
        retry: false,
        staleTime: 0,
        gcTime: 0,
        enabled: !!eventId && !!orderShortId,
    });
};
