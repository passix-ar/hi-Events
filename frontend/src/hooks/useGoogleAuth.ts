import {useState} from "react";
import {useMutation} from "@tanstack/react-query";
import {useNavigate} from "react-router";
import {t} from "@lingui/macro";
import {authClient} from "../api/auth.client.ts";
import {redirectToPreviousUrl} from "../api/client.ts";
import {showError} from "../utilites/notifications.tsx";
import {Account, GoogleLoginRequest, GoogleLoginResponse, IdParam, isRegistrationRequired} from "../types.ts";


/**
 * Drives the Google sign-in exchange for both the login and register screens.
 *
 * Everything after Google hands back a credential is identical on both, so the flow and
 * its error handling live here rather than being repeated per screen.
 */
export const useGoogleAuth = () => {
    const navigate = useNavigate();
    const [accounts, setAccounts] = useState<Account[] | null>(null);
    const [pendingIdToken, setPendingIdToken] = useState<string | null>(null);

    const {mutate, isPending} = useMutation({
        mutationFn: (loginData: GoogleLoginRequest) => authClient.googleLogin(loginData),

        onSuccess: (response: GoogleLoginResponse) => {
            if (isRegistrationRequired(response)) {
                // The token carries the verified identity; it never goes in the URL.
                navigate('/auth/complete-registration', {
                    state: {
                        registrationToken: response.registration_token,
                        email: response.email,
                    },
                });
                return;
            }

            if (response.token) {
                redirectToPreviousUrl();
                return;
            }

            if (response.accounts.length > 1) {
                setAccounts(response.accounts);
            }
        },

        onError: (error: any) => {
            showError(resolveErrorMessage(error));
        },
    });

    const handleCredential = (idToken: string) => {
        setPendingIdToken(idToken);
        mutate({id_token: idToken});
    };

    const onAccountChosen = (accountId: IdParam) => {
        if (!pendingIdToken || accountId === undefined) {
            return;
        }

        mutate({id_token: pendingIdToken, account_id: accountId});
    };

    return {handleCredential, isPending, accounts, onAccountChosen};
};

const resolveErrorMessage = (error: any): string => {
    if (!error?.response) {
        return t`We couldn't reach the server. Please check your connection and try again.`;
    }

    switch (error.response.status) {
        case 401:
            return t`We couldn't verify your Google sign in. Please try again.`;
        case 403:
            return t`Signing in with Google is not available right now.`;
        case 409:
            return error.response.data?.message
                ?? t`This Google account is already linked to another user. Please contact support.`;
        case 429:
            return t`Too many attempts. Please wait a moment and try again.`;
        default:
            return t`Something went wrong signing in with Google. Please try again.`;
    }
};
