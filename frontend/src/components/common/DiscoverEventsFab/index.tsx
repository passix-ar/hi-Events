import {t} from "@lingui/macro";
import {IconArrowUpRight} from "@tabler/icons-react";
import classes from "./DiscoverEventsFab.module.scss";

const MAIN_SITE_URL = "https://getpassix.com";

export const DiscoverEventsFab = () => {
    return (
        <a
            className={classes.fab}
            href={MAIN_SITE_URL}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t`Ver más eventos en Passix`}
        >
            <span className={classes.dot} aria-hidden="true"/>
            <span className={classes.label}>{t`Ver más eventos`}</span>
            <IconArrowUpRight className={classes.icon} size={16} stroke={2.5}/>
        </a>
    );
};
