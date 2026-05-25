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
