import {t} from "@lingui/macro";
import classes from "./LandingBannerPreview.module.scss";

interface LandingBannerPreviewProps {
    imageUrl: string;
}

/**
 * The banner never appears on the event homepage, so the live preview iframe cannot show
 * it. This stands in for it, and deliberately renders the artwork whole at its own
 * proportion: the featured slot does not crop the organiser's banner, so neither does this.
 */
export const LandingBannerPreview = ({imageUrl}: LandingBannerPreviewProps) => (
    <figure className={classes.preview}>
        <img src={imageUrl} alt="" className={classes.art}/>
        <figcaption className={classes.caption}>
            {t`How it looks in the featured slot on the Passix homepage.`}
        </figcaption>
    </figure>
);
