import {useMutation, useQueryClient} from "@tanstack/react-query";
import {RegisterAccountRequest} from "../types.ts";
import {authClient} from "../api/auth.client.ts";

export const useRegisterAccount = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({registerData}: {
            registerData: RegisterAccountRequest,
        }) => authClient.register(registerData),

        // Registration logs the user into a brand-new account (the backend swaps the
        // auth cookie). If a previous account was already authenticated in this tab
        // (e.g. the user registered, went back, and registered again with another
        // email), React Query still holds the previous identity in cache. Requests
        // would then go out with a mismatched identity and 401 ("no autorizado").
        // removeQueries() drops every cached query so the new account starts from a
        // clean slate, without touching the in-flight mutation cache (clear() would).
        onSuccess: () => {
            if (typeof window !== "undefined") {
                window.localStorage.removeItem("token");
            }
            queryClient.removeQueries();
        },
    });
}
