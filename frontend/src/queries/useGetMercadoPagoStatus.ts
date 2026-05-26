import {useQuery} from "@tanstack/react-query";
import {accountClient} from "../api/account.client.ts";
import {IdParam} from "../types.ts";

export const GET_MP_STATUS_QUERY_KEY = 'getMercadoPagoStatus';

export const useGetMercadoPagoStatus = (accountId: IdParam) => {
    return useQuery({
        queryKey: [GET_MP_STATUS_QUERY_KEY, accountId],
        queryFn: () => accountClient.getMercadoPagoStatus(accountId),
        enabled: !!accountId,
    });
};
