import {i18n} from "@lingui/core";

export type SupportedLocales =
    "es"
    | "en"
    | "pt-br"
    | "pt"
    | "fr"
    | "it";

export const availableLocales = ["es", "en", "pt-br", "pt", "fr", "it"];

export const localeToFlagEmojiMap: Record<SupportedLocales, string> = {
    es: '🇦🇷',
    en: '🇺🇸',
    "pt-br": '🇧🇷',
    pt: '🇵🇹',
    fr: '🇫🇷',
    it: '🇮🇹',
};

export const localeToNameMap: Record<SupportedLocales, string> = {
    es: `Español`,
    en: `English`,
    "pt-br": `Português (Brasil)`,
    pt: `Português`,
    fr: `Français`,
    it: `Italiano`,
};

export const getLocaleName = (locale: SupportedLocales) => {
    return localeToNameMap[locale];
}

export const getClientLocale = () => {
    if (typeof window !== "undefined") {
        const storedLocale = document
            .cookie
            .split(";")
            .find((c) => c.includes("locale="))
            ?.split("=")[1];

        if (storedLocale) {
            return getSupportedLocale(storedLocale);
        }
    }

    return "es";
};

export const setLocaleCookie = (locale: string) => {
    if (typeof document === "undefined" || typeof window === "undefined") {
        return;
    }

    // Scope the cookie to the parent domain (e.g. .getpassix.com) when running on a
    // subdomain, so it's also sent to the API subdomain (api.getpassix.com). Without this
    // a host-only cookie on app.getpassix.com never reaches the API and the backend falls
    // back to the user's stored locale. On localhost / IP / apex hosts it stays host-only.
    const host = window.location.hostname;
    const labels = host.split(".");
    const isIp = /^[0-9.]+$/.test(host);
    const domainAttr = (!isIp && host !== "localhost" && labels.length > 2)
        ? `;domain=.${labels.slice(-2).join(".")}`
        : "";
    const secure = window.location.protocol === "https:" ? ";secure" : "";

    document.cookie = `locale=${locale};path=/;max-age=31536000;samesite=lax${domainAttr}${secure}`;
};

export async function dynamicActivateLocale(locale: string) {
    try {
        locale = availableLocales.includes(locale) ? locale : "es";
        const module = (await import(`./locales/${locale}.po`));
        i18n.load(locale, module.messages);
        i18n.activate(locale);
    } catch (error) {
        console.error("Error loading locale:", error);
        // i18n.activate("en");
    }
}

export const getSupportedLocale = (userLocale: string) => {
    const normalizedLocale = userLocale.toLowerCase();

    if (availableLocales.includes(normalizedLocale)) {
        return normalizedLocale;
    }

    const mainLanguage = normalizedLocale.split('-')[0];
    const mainLocale = availableLocales.find(locale => locale.startsWith(mainLanguage));
    if (mainLocale) {
        return mainLocale;
    }

    return "es";
};
