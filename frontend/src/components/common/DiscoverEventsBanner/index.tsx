import {t} from "@lingui/macro";
import {IconArrowUpRight} from "@tabler/icons-react";
import classes from "./DiscoverEventsBanner.module.scss";

const MAIN_SITE_URL = "https://getpassix.com";

export const DiscoverEventsBanner = () => {
    return (
        <a
            className={classes.banner}
            href={MAIN_SITE_URL}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t`Ver más eventos en Passix`}
        >
            <span className={classes.dot} aria-hidden="true"/>
            <span className={classes.text}>
                <span className={classes.title}>{t`Descubrí más eventos en Passix`}</span>
                <span className={classes.subtitle}>{t`Mirá toda la cartelera en getpassix.com`}</span>
            </span>
            <IconArrowUpRight className={classes.icon} size={18} stroke={2.5}/>
        </a>
    );
};
