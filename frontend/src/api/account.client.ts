import {api} from "./client.ts";
import {Account, GenericDataResponse, IdParam, User, StripeConnectAccountsResponse} from "../types.ts";

interface CreateAccountRequest {
    first_name: string;
    last_name: string;
    email: string;
    password?: string;
}

export const accountClient = {
    create: async (account: CreateAccountRequest) => {
        const response = await api.post<GenericDataResponse<User>>('accounts', account);
        return response.data;
    },
    getAccount: async () => {
        const response = await api.get<GenericDataResponse<Account>>('accounts');
        return response.data;
    },
    updateAccount: async (account: Account) => {
        const response = await api.put<GenericDataResponse<Account>>('accounts', account);
        return response.data;
    },
    getStripeConnectDetails: async (accountId: IdParam, platform?: string) => {
        const response = await api.post<GenericDataResponse<any>>(`accounts/${accountId}/stripe/connect`, {
            platform
        });
        return response.data;
    },
    getStripeConnectAccounts: async (accountId: IdParam) => {
        const response = await api.get<GenericDataResponse<StripeConnectAccountsResponse>>(`accounts/${accountId}/stripe/connect_accounts`);
        return response.data;
    },
    getMercadoPagoConnectUrl: async (accountId: IdParam) => {
        const response = await api.get<{
            authorization_url: string;
            is_connected: boolean;
        }>(`accounts/${accountId}/mercadopago/connect`);
        return response.data;
    },
    getMercadoPagoStatus: async (accountId: IdParam) => {
        const response = await api.get<{
            is_connected: boolean;
            mp_user_id: string | null;
            connected_at: string | null;
        }>(`accounts/${accountId}/mercadopago/status`);
        return response.data;
    },
    disconnectMercadoPago: async (accountId: IdParam) => {
        const response = await api.delete<{ is_connected: boolean }>(`accounts/${accountId}/mercadopago`);
        return response.data;
    },
}