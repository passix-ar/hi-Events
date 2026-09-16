import {t} from "@lingui/macro";
import classes from "./LandingBannerPreview.module.scss";

interface LandingBannerPreviewProps {
    imageUrl: string;
}

/**
 * The banner never appears on the event homepage, so the live preview iframe cannot show
 * it. This stands in for it, and frames the artwork exactly as the featured strip does: a
 * 3:1 box, edge to edge, with anything outside that shape cropped from the centre. What
 * the organiser sees here is what the Passix homepage shows — including the crop.
 */
export const LandingBannerPreview = ({imageUrl}: LandingBannerPreviewProps) => (
    <figure className={classes.preview}>
        <img src={imageUrl} alt="" className={classes.art}/>
        <figcaption className={classes.caption}>
            {t`How it looks in the featured slot on the Passix homepage.`}
        </figcaption>
    </figure>
);
