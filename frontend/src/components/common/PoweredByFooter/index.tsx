import {t} from "@lingui/macro";
import classes from "./FloatingPoweredBy.module.scss";
import classNames from "classnames";
import React from "react";
import {iHavePurchasedALicence} from "../../../utilites/helpers.ts";

/**
 * (c) Hi.Events Ltd 2025
 *
 * PLEASE NOTE:
 *
 * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
 *
 * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
 *
 * In accordance with Section 7(b) of the AGPL, you must retain the "Powered by Hi.Events" notice.
 *
 * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
 */
export const PoweredByFooter = (
    props: React.DetailedHTMLProps<React.HTMLAttributes<HTMLDivElement>, HTMLDivElement>
) => {
    if (iHavePurchasedALicence()) {
        return <></>;
    }

    const footerContent = (
        <>
            {t`Powered by`}{" "}
            <a
                href="https://passix.com.ar"
                target="_blank"
                title={"Passix — ticketing para tus eventos"}
            >
                Passix
            </a>
            {", "}
            {t`based on`}{" "}
            <a
                href="https://hi.events"
                target="_blank"
                title={"Hi.Events open source event platform"}
            >
                Hi.Events
            </a>
            {" ("}
            <a
                href="https://github.com/passix-ar/hi-Events"
                target="_blank"
                title={"Source code — AGPL v3"}
            >
                {t`source`}
            </a>
            {")"}
        </>
    );

    return (
        <div {...props} className={classNames(classes.poweredBy, props.className)}>
            <div className={classes.poweredByText}>
                {footerContent}
            </div>
        </div>
    );
}
