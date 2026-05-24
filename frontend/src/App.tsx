import React, {FC, PropsWithChildren, useCallback, useEffect} from "react";
import {MantineProvider, type CSSVariablesResolver} from "@mantine/core";
import {Notifications} from "@mantine/notifications";
import {i18n} from "@lingui/core";
import {I18nProvider} from "@lingui/react";
import {ModalsProvider} from "@mantine/modals";
import {HydrationBoundary, QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {Helmet, HelmetProvider} from "react-helmet-async";
import {generateColors} from '@mantine/colors-generator';

const passixResolver: CSSVariablesResolver = () => ({
    variables: {},
    dark: {
        '--mantine-color-body':           '#16161d',
        '--mantine-color-default':        '#16161d',
        '--mantine-color-default-hover':  '#1e1e27',
        '--mantine-color-default-border': '#26262f',
        '--mantine-color-dimmed':         '#8e8e98',
        '--mantine-color-text':           '#f4f1ea',
        '--mantine-color-dark-6':         '#16161d',
        '--mantine-color-dark-5':         '#1e1e27',
        '--mantine-color-dark-4':         '#26262f',
    },
    light: {},
});

import "@mantine/core/styles/global.css";
import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";
import "@mantine/tiptap/styles.css";
import "@mantine/dropzone/styles.css";
import '@mantine/dates/styles.css';
import "@mantine/charts/styles.css";
import "./styles/global.scss";
import {isSsr} from "./utilites/helpers.ts";
import {StartupChecks} from "./StartupChecks.tsx";
import {ThirdPartyScripts} from "./components/common/ThirdPartyScripts";
import {getConfig} from "./utilites/config.ts";
import {CookieConsentBanner} from "./components/common/CookieConsentBanner";
import {isConsentPending, setConsentState, updateGoogleConsentMode} from "./utilites/trackingPixels/consent";

declare global {
    interface Window {
        hievents: Record<string, string>;
    }
}

export const App: FC<
    PropsWithChildren<{
        queryClient: QueryClient;
        locale: string;
        helmetContext?: any;
        dehydratedState?: unknown;
    }>
> = (props) => {
    const [isLoadedOnBrowser, setIsLoadedOnBrowser] = React.useState(false);
    const showGlobalConsentBanner = getConfig('VITE_COOKIE_CONSENT_ENABLED') === 'true'
        && !isSsr() && isConsentPending();

    const handleGlobalConsent = useCallback((granted: boolean) => {
        setConsentState(granted ? 'granted' : 'denied');
        updateGoogleConsentMode(granted);
        window.dispatchEvent(new CustomEvent('hi_consent_change', {detail: {granted}}));
    }, []);

    useEffect(() => {
        setIsLoadedOnBrowser(!isSsr());
    }, []);

    return (
        <React.StrictMode>
            <div
                className="ssr-loader"
                style={{
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    margin: 0,
                    padding: 0,
                    width: "100vw",
                    height: "100vh",
                    position: "fixed",
                    background: "#0b0b0e",
                    zIndex: 1000,
                    display: isLoadedOnBrowser ? "none" : "block",
                }}
            />
            <MantineProvider
                forceColorScheme="dark"
                cssVariablesResolver={passixResolver}
                theme={{
                    colors: {
                        primary: generateColors(getConfig("VITE_APP_PRIMARY_COLOR", "#d6ff3d") as string),
                        secondary: generateColors(getConfig("VITE_APP_SECONDARY_COLOR", "#b4e000") as string),
                    },
                    primaryColor: "primary",
                    fontFamily: "'Hanken Grotesk', sans-serif",
                    headings: { fontFamily: "'Syne', sans-serif" },
                    primaryShade: { dark: 6, light: 8 },
                    autoContrast: true,
                }}
            >
                <HelmetProvider context={props.helmetContext}>
                    <I18nProvider i18n={i18n}>
                        <QueryClientProvider client={props.queryClient}>
                            <HydrationBoundary state={props.dehydratedState}>
                                <StartupChecks/>
                                <ThirdPartyScripts/>
                                <ModalsProvider>
                                    <Helmet>
                                        <title>{getConfig("VITE_APP_NAME", "Hi.Events")}</title>
                                        <link rel="icon"
                                              type="image/svg+xml"
                                              href={getConfig("VITE_APP_FAVICON", "/favicon.svg")}
                                        />
                                    </Helmet>
                                    {props.children}
                                </ModalsProvider>
                                <Notifications/>
                                {showGlobalConsentBanner && (
                                    <CookieConsentBanner onConsent={handleGlobalConsent}/>
                                )}
                            </HydrationBoundary>
                        </QueryClientProvider>
                    </I18nProvider>
                </HelmetProvider>
            </MantineProvider>
        </React.StrictMode>
    );
};
