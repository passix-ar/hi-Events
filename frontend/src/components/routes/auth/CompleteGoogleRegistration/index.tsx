import {Button, TextInput} from "@mantine/core";
import {hasLength, useForm} from "@mantine/form";
import {useLocation, useNavigate} from "react-router";
import {useMutation} from "@tanstack/react-query";
import {t, Trans} from "@lingui/macro";
import {useEffect} from "react";
import {authClient} from "../../../../api/auth.client.ts";
import {redirectToPreviousUrl} from "../../../../api/client.ts";
import {CompleteSocialRegistrationRequest} from "../../../../types.ts";
import {getClientLocale} from "../../../../locales.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {showError} from "../../../../utilites/notifications.tsx";
import {clearStoredUtmData, getStoredUtmData} from "../../../../utilites/utm.ts";
import classes from "./CompleteGoogleRegistration.module.scss";

interface CompleteRegistrationState {
    registrationToken?: string;
    email?: string;
}

/**
 * Collects the details Google cannot provide before the account is created.
 *
 * Reached only via navigation state from the Google sign-in exchange; landing here
 * directly means there is no registration token, so we send the user back to sign in.
 */
const CompleteGoogleRegistration = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const state = location.state as CompleteRegistrationState | null;
    const registrationToken = state?.registrationToken;

    const form = useForm({
        validateInputOnBlur: true,
        initialValues: {
            business_name: '',
            timezone: typeof window !== 'undefined'
                ? Intl.DateTimeFormat().resolvedOptions().timeZone
                : 'UTC',
            locale: getClientLocale(),
            // Passix operates in ARS only (see backend/data/currencies.php).
            currency_code: 'ARS',
            marketing_opt_in: false,
        },
        validate: {
            business_name: hasLength({min: 1}, t`Please enter your business name`),
        },
    });

    const errorHandler = useFormErrorResponseHandler();

    const {mutate, isPending} = useMutation({
        mutationFn: (data: CompleteSocialRegistrationRequest) =>
            authClient.completeSocialRegistration(data),

        onSuccess: () => {
            clearStoredUtmData();
            redirectToPreviousUrl();
        },

        onError: (error: any) => {
            if (!error?.response) {
                showError(t`We couldn't reach the server. Please check your connection and try again.`);
                return;
            }

            if (error.response.status === 401) {
                showError(t`Your sign up session has expired. Please start again.`);
                navigate('/auth/login');
                return;
            }

            if (error.response.status === 429) {
                showError(t`Too many attempts. Please wait a moment and try again.`);
                return;
            }

            errorHandler(form, error, error.response.data?.message);
        },
    });

    useEffect(() => {
        if (!registrationToken) {
            navigate('/auth/login', {replace: true});
        }
    }, [registrationToken]);

    if (!registrationToken) {
        return null;
    }

    const handleSubmit = (values: typeof form.values) => {
        const utmData = getStoredUtmData();

        mutate({
            ...values,
            ...(utmData ?? {}),
            registration_token: registrationToken,
        } as CompleteSocialRegistrationRequest);
    };

    return (
        <>
            <header className={classes.header}>
                <h2>{t`One last step`}</h2>
                <p>
                    <Trans>
                        You're signing up as {state?.email}
                    </Trans>
                </p>
            </header>

            <div className={classes.card}>
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <TextInput
                        {...form.getInputProps('business_name')}
                        label={t`Business name`}
                        placeholder={t`Acme Inc.`}
                        description={t`This name appears on your event pages and in emails.`}
                        required
                        data-autofocus
                    />

                    <Button
                        color="secondary.5"
                        type="submit"
                        fullWidth
                        loading={isPending}
                        disabled={isPending}
                        mt="lg"
                    >
                        {isPending ? t`Creating your account` : t`Create account`}
                    </Button>
                </form>
            </div>
        </>
    );
};

export default CompleteGoogleRegistration;
