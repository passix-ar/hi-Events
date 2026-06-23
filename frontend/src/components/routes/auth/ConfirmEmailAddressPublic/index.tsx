import {useEffect, useRef, useState} from "react";
import {useParams} from "react-router";
import {Button} from "@mantine/core";
import {t} from "@lingui/macro";
import {useConfirmEmailAddressPublic} from "../../../../mutations/useConfirmEmailAddressPublic.ts";

type Status = 'confirming' | 'success' | 'error';

/**
 * Public, session-less email confirmation page. It is intentionally NOT wrapped in
 * AuthLayout (which redirects logged-in users and calls /users/me) so the link from
 * the email works regardless of whether the user is logged in or which device they
 * open it on. The signed token alone authorizes the confirmation.
 */
const ConfirmEmailAddressPublic = () => {
    const {token} = useParams();
    const [status, setStatus] = useState<Status>('confirming');
    const confirmMutation = useConfirmEmailAddressPublic();
    const hasRun = useRef(false);

    useEffect(() => {
        if (hasRun.current || !token) {
            return;
        }
        hasRun.current = true;

        confirmMutation.mutate({token}, {
            onSuccess: () => setStatus('success'),
            onError: () => setStatus('error'),
        });
    }, [token]);

    return (
        <div style={{
            minHeight: '100vh',
            background: '#0b0b0e',
            color: '#fff',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: 24,
        }}>
            <div style={{
                maxWidth: 420,
                width: '100%',
                background: '#15151b',
                border: '1px solid #26262f',
                borderRadius: 16,
                padding: 32,
                textAlign: 'center',
            }}>
                <img
                    src={"/logos/passix-dark-bg.svg"}
                    alt={"Passix"}
                    style={{height: 32, marginBottom: 24}}
                />

                {status === 'confirming' && (
                    <p>{t`Confirming your email address...`}</p>
                )}

                {status === 'success' && (
                    <>
                        <h2 style={{marginBottom: 8}}>{t`Email confirmed!`}</h2>
                        <p style={{color: '#a1a1aa', marginBottom: 24}}>
                            {t`Your email address has been verified. You can now publish events and start selling tickets.`}
                        </p>
                        <Button
                            component="a"
                            href="/manage/events"
                            color="secondary.5"
                            fullWidth
                        >
                            {t`Go to my dashboard`}
                        </Button>
                    </>
                )}

                {status === 'error' && (
                    <>
                        <h2 style={{marginBottom: 8}}>{t`Confirmation link invalid`}</h2>
                        <p style={{color: '#a1a1aa', marginBottom: 24}}>
                            {t`This confirmation link is invalid or has expired. Log in and request a new one from your profile.`}
                        </p>
                        <Button
                            component="a"
                            href="/auth/login"
                            color="secondary.5"
                            fullWidth
                        >
                            {t`Go to login`}
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
};

export default ConfirmEmailAddressPublic;
