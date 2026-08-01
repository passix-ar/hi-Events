import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {accountClient} from "../api/account.client.ts";

export const GET_MERCADOPAGO_DISCONNECT_STATUS_QUERY_KEY = 'getMercadoPagoDisconnectStatus';

export const useGetMercadoPagoDisconnectStatus = (accountId: IdParam, enabled = true) => {
    return useQuery({
        queryKey: [GET_MERCADOPAGO_DISCONNECT_STATUS_QUERY_KEY, accountId],

        staleTime: 0,
        enabled: enabled && !!accountId,
        queryFn: async () => {
            const {data} = await accountClient.getMercadoPagoDisconnectStatus(accountId);
            return data;
        }
    });
};
