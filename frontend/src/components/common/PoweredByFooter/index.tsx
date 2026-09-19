import classes from "./FloatingPoweredBy.module.scss";
import classNames from "classnames";
import React from "react";

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
 *
 * Passix: licencia comercial adquirida (sept. 2026). El aviso de copyright se conserva; la marca en la UI no.
 */
export const PoweredByFooter = (
    props: React.DetailedHTMLProps<React.HTMLAttributes<HTMLDivElement>, HTMLDivElement>
) => {
    return (
        <div {...props} className={classNames(classes.poweredBy, props.className)}>
            <div className={classes.poweredByText}>
                <a
                    href="https://getpassix.com"
                    target="_blank"
                    title={"Passix — ticketing para tus eventos"}
                >
                    Passix
                </a>
            </div>
        </div>
    );
}
