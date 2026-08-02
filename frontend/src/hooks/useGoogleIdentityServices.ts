import {useEffect, useState} from "react";
import {authClient} from "../api/auth.client.ts";
import {getConfig} from "../utilites/config.ts";
import {isSsr} from "../utilites/helpers.ts";

const GOOGLE_SCRIPT_SRC = 'https://accounts.google.com/gsi/client';
const GOOGLE_SCRIPT_ID = 'google-identity-services';

export type GoogleIdentityStatus = 'disabled' | 'loading' | 'ready' | 'unavailable';

export interface GoogleCredentialResponse {
    credential: string;
}

export interface GoogleButtonOptions {
    theme: 'outline' | 'filled_blue' | 'filled_black';
    size: 'large' | 'medium' | 'small';
    text: 'signin_with' | 'signup_with' | 'continue_with';
    shape: 'rectangular' | 'pill';
    logo_alignment?: 'left' | 'center';
    locale?: string;
    width?: number;
}

interface GoogleIdentityServices {
    accounts: {
        id: {
            initialize: (options: Record<string, unknown>) => void;
            renderButton: (parent: HTMLElement, options: GoogleButtonOptions) => void;
        };
    };
}

declare global {
    interface Window {
        google?: GoogleIdentityServices;
    }
}

export const isGoogleAuthEnabled = (): boolean =>
    getConfig('VITE_GOOGLE_AUTH_ENABLED') === 'true' && !!getConfig('VITE_GOOGLE_CLIENT_ID');

/**
 * The nonce is deliberately not generated here. A value the browser both invents and
 * hands back proves nothing, since anyone holding the token can read the nonce out of its
 * payload. The backend mints it and spends it on first use, which is what makes a
 * captured token useless the second time.
 */
export const fetchNonce = async (): Promise<string> => {
    const {nonce} = await authClient.getSocialAuthNonce();

    return nonce;
};

/**
 * Loads Google's Identity Services script once per page.
 *
 * Resolves to 'unavailable' rather than throwing when the script cannot load, which is
 * common enough in practice — ad blockers, privacy extensions and offline devices all
 * block it — that the caller must be able to fall back to email and password.
 */
export const useGoogleIdentityServices = (): GoogleIdentityStatus => {
    const [status, setStatus] = useState<GoogleIdentityStatus>(
        () => isGoogleAuthEnabled() ? 'loading' : 'disabled'
    );

    useEffect(() => {
        if (isSsr() || !isGoogleAuthEnabled()) {
            return;
        }

        if (window.google?.accounts?.id) {
            setStatus('ready');
            return;
        }

        const existingScript = document.getElementById(GOOGLE_SCRIPT_ID) as HTMLScriptElement | null;
        const script = existingScript ?? document.createElement('script');

        const handleLoad = () => setStatus(window.google?.accounts?.id ? 'ready' : 'unavailable');
        const handleError = () => setStatus('unavailable');

        script.addEventListener('load', handleLoad);
        script.addEventListener('error', handleError);

        if (!existingScript) {
            script.id = GOOGLE_SCRIPT_ID;
            script.src = GOOGLE_SCRIPT_SRC;
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        }

        return () => {
            script.removeEventListener('load', handleLoad);
            script.removeEventListener('error', handleError);
        };
    }, []);

    return status;
};
