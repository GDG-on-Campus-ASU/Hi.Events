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
                href={"https://kmteam.tech"}
                target="_blank"
                title={"Powered by KMTeam LLC"}
                style={{display: "inline-flex", alignItems: "center", gap: "4px"}}
            >
                <img
                    src={"https://cdn.kmteam.tech/Images/KMTeam-n.png"}
                    alt={"KMTeam LLC"}
                    style={{height: "20px", verticalAlign: "middle"}}
                />
                KMTeam LLC
            </a>
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
