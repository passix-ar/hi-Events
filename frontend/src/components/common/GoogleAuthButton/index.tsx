import {useEffect, useRef, useState} from "react";
import {t} from "@lingui/macro";
import {getClientLocale} from "../../../locales.ts";
import {getConfig} from "../../../utilites/config.ts";
import {
    fetchNonce,
    GoogleCredentialResponse,
    isGoogleAuthEnabled,
    useGoogleIdentityServices,
} from "../../../hooks/useGoogleIdentityServices.ts";
import classes from "./GoogleAuthButton.module.scss";

// Google clamps its button to 400px and ignores anything larger, so mirror that here
// instead of handing it a width it will silently discard.
const GOOGLE_MAX_BUTTON_WIDTH = 400;

const resolveButtonWidth = (container: HTMLElement): number | undefined => {
    const available = container.getBoundingClientRect().width;

    if (!available) {
        return undefined;
    }

    return Math.min(Math.round(available), GOOGLE_MAX_BUTTON_WIDTH);
};

interface GoogleAuthButtonProps {
    onCredential: (idToken: string) => void;
    text?: 'signin_with' | 'signup_with' | 'continue_with';
    disabled?: boolean;
}

/**
 * Renders Google's own sign-in button.
 *
 * Google only issues ID tokens through a button it renders itself, so this deliberately
 * does not reimplement the control. A custom button would mean the OAuth code flow, which
 * needs a client secret on the server and buys us nothing here.
 */
export const GoogleAuthButton = ({
                                     onCredential,
                                     text = 'continue_with',
                                     disabled = false,
                                 }: GoogleAuthButtonProps) => {
    const status = useGoogleIdentityServices();
    const [nonceFailed, setNonceFailed] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    // Kept in a ref so the callback Google holds always reads the latest handler.
    const onCredentialRef = useRef(onCredential);
    onCredentialRef.current = onCredential;

    useEffect(() => {
        const container = containerRef.current;

        if (status !== 'ready' || !container || !window.google) {
            return;
        }

        let cancelled = false;
        let observer: ResizeObserver | undefined;
        let renderedWidth: number | undefined;

        const render = () => {
            const width = resolveButtonWidth(container);

            // Redrawing replaces the container's contents and so changes its height, which
            // would notify the observer again. Reacting to width alone keeps that from
            // looping, and the width is the only thing Google bakes in as a fixed value.
            if (width === renderedWidth) {
                return;
            }

            renderedWidth = width;

            window.google?.accounts.id.renderButton(container, {
                theme: 'outline',
                size: 'large',
                shape: 'rectangular',
                logo_alignment: 'left',
                text,
                locale: getClientLocale(),
                width,
            });
        };

        const initializeWithNonce = (nonce: string) => {
            window.google?.accounts.id.initialize({
                client_id: getConfig('VITE_GOOGLE_CLIENT_ID'),
                nonce,
                ux_mode: 'popup',
                auto_select: false,
                cancel_on_tap_outside: true,
                callback: (response: GoogleCredentialResponse) => {
                    // Google stays silent when the user closes the popup, so there is no
                    // cancellation branch here — no credential simply means no call.
                    if (response?.credential) {
                        onCredentialRef.current(response.credential);

                        // The backend spends the nonce the moment it sees this credential,
                        // so re-arm with a fresh one or every retry would be rejected.
                        void fetchNonce()
                            .then((freshNonce) => {
                                if (!cancelled) {
                                    initializeWithNonce(freshNonce);
                                }
                            })
                            .catch(() => {
                                // Keeping the stale nonce only affects a retry, which would
                                // fail with the same clear error as before this re-arm existed.
                            });
                    }
                },
            });
        };

        // The nonce comes from our backend, so setup has to wait for that round trip.
        const setUpButton = async () => {
            let nonce: string;

            try {
                nonce = await fetchNonce();
            } catch {
                if (!cancelled) {
                    setNonceFailed(true);
                }
                return;
            }

            if (cancelled || !window.google) {
                return;
            }

            initializeWithNonce(nonce);

            render();

            observer = new ResizeObserver(render);
            observer.observe(container);
        };

        setNonceFailed(false);
        void setUpButton();

        return () => {
            cancelled = true;
            observer?.disconnect();
        };
    }, [status, text]);

    if (!isGoogleAuthEnabled() || status === 'disabled') {
        return null;
    }

    if (status === 'unavailable' || nonceFailed) {
        return (
            <p className={classes.unavailable}>
                {t`Google sign in is unavailable right now. Please use your email and password.`}
            </p>
        );
    }

    return (
        <div className={classes.wrapper} data-disabled={disabled}>
            <div ref={containerRef} className={classes.button}/>
            {status === 'loading' && <div className={classes.placeholder}/>}
        </div>
    );
};
