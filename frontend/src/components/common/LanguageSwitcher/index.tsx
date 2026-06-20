import {Select} from "@mantine/core";
import {dynamicActivateLocale, getClientLocale, localeToNameMap, setLocaleCookie, SupportedLocales} from "../../../locales.ts";
import {t} from "@lingui/macro";
import {IconWorld} from "@tabler/icons-react";
import {useLingui} from "@lingui/react";

export const LanguageSwitcher = () => {
    useLingui();

    // Ideally these would be in the locales.ts file, but when they're there they don't translate
    const getLocaleName = (locale: SupportedLocales): string => {
        switch (locale) {
            case "es":
                return "Español";
            case "en":
                return "English";
            case "pt-br":
                return "Português (Brasil)";
            case "pt":
                return "Português";
            case "fr":
                return "Français";
            case "it":
                return "Italiano";
            default:
                return locale;
        }
    };

    return (
        <>
            <Select
                leftSection={<IconWorld size={15} color={'#ccc'}/>}
                width={180}
                size={'xs'}
                required
                data={Object.keys(localeToNameMap).map(locale => ({
                    value: locale,
                    label: getLocaleName(locale as SupportedLocales),
                }))}
                defaultValue={getClientLocale()}
                placeholder={t`English`}
                onChange={(value) =>
                    dynamicActivateLocale(value as string).then(() => {
                        setLocaleCookie(value as string);
                        // this shouldn't be necessary, but it is due to the wide use of t`...` in the codebase
                        window.location.reload();
                    })}
            />
        </>
    )
}
