import {t} from "@lingui/macro";
import {IconAlertTriangle, IconCloudCheck, IconCloudOff, IconCloudUpload, IconRefresh} from "@tabler/icons-react";
import {UnstyledButton} from "@mantine/core";
import {useEffect, useState} from "react";
import classes from "./SyncStatus.module.scss";
import {ROSTER_REFRESH_MS} from "../../../../hooks/useCheckInRoster";
import {relativeDate} from "../../../../utilites/dates";

interface SyncStatusProps {
    online: boolean;
    pendingCount: number;
    loadedAt: number | null;
    isLoading: boolean;
    loadError: boolean;
    onRetry: () => void;
}

// Three missed refresh cycles. One is noise — a single failed request, a slow page — three is a
// fault the door should hear about.
const STALE_AFTER_MS = ROSTER_REFRESH_MS * 3;

// The age has to keep moving on screen. The refresh cycle re-renders this most of the time, but in
// the worst case — the list never loaded, so the cycle never starts — the text would freeze at
// whatever it said first, which is the same lie this is meant to fix.
const AGE_TICK_MS = 30_000;

/**
 * One line under the header that tells the person at the door what the scanner
 * is doing behind the scenes. Normally it says nothing worth reading; it exists
 * for the moments that matter: the connection dropped, the list could not be
 * downloaded, or the list is quietly out of date.
 */
export const SyncStatus = ({online, pendingCount, loadedAt, isLoading, loadError, onRetry}: SyncStatusProps) => {
    // This renders on the server too, where there is no localStorage and loadedAt is always null.
    // Comparing against the clock before mounting would give the server and the client different
    // text and break hydration.
    const [mounted, setMounted] = useState(false);
    const [, setAgeTick] = useState(0);

    useEffect(() => {
        setMounted(true);
        const interval = setInterval(() => setAgeTick(tick => tick + 1), AGE_TICK_MS);
        return () => clearInterval(interval);
    }, []);

    if (loadError && loadedAt === null) {
        return (
            <UnstyledButton className={`${classes.status} ${classes.error}`} onClick={onRetry}>
                <IconRefresh size={16}/>
                {t`Could not load the attendee list. Tap to retry.`}
            </UnstyledButton>
        );
    }

    if (!online) {
        return (
            <div className={`${classes.status} ${classes.offline}`}>
                <IconCloudOff size={16}/>
                {pendingCount > 0
                    ? t`Offline — ${pendingCount} check-in(s) will sync when back online`
                    : t`Offline — scanning still works`}
            </div>
        );
    }

    if (pendingCount > 0) {
        return (
            <div className={`${classes.status} ${classes.pending}`}>
                <IconCloudUpload size={16}/>
                {t`Syncing ${pendingCount} check-in(s)…`}
            </div>
        );
    }

    if (isLoading && loadedAt === null) {
        return (
            <div className={classes.status}>
                <IconCloudUpload size={16}/>
                {t`Loading attendee list…`}
            </div>
        );
    }

    // Nothing pending and the connection looks fine, but the list itself is behind: sales made
    // since then are missing and so are the check-ins from the other phone at the door. Keyed off
    // how old the list actually is rather than off the last request failing, so it also covers the
    // refresh that never started and the one still stuck in flight.
    if (mounted && loadedAt !== null && Date.now() - loadedAt > STALE_AFTER_MS) {
        const age = relativeDate(new Date(loadedAt).toISOString());

        return (
            <UnstyledButton className={`${classes.status} ${classes.stale}`} onClick={onRetry}>
                <IconAlertTriangle size={16}/>
                {t`Attendee list last updated ${age} — tap to refresh`}
            </UnstyledButton>
        );
    }

    return (
        <div className={`${classes.status} ${classes.ok}`}>
            <IconCloudCheck size={16}/>
            {t`All check-ins synced`}
        </div>
    );
};
