import {t} from "@lingui/macro";
import {IconCloudCheck, IconCloudOff, IconCloudUpload, IconRefresh} from "@tabler/icons-react";
import {UnstyledButton} from "@mantine/core";
import classes from "./SyncStatus.module.scss";

interface SyncStatusProps {
    online: boolean;
    pendingCount: number;
    loadedAt: number | null;
    isLoading: boolean;
    loadError: boolean;
    onRetry: () => void;
}

/**
 * One line under the header that tells the person at the door what the scanner
 * is doing behind the scenes. Normally it says nothing worth reading; it exists
 * for the two moments that matter: the connection dropped, and the list could
 * not be downloaded.
 */
export const SyncStatus = ({online, pendingCount, loadedAt, isLoading, loadError, onRetry}: SyncStatusProps) => {
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

    return (
        <div className={`${classes.status} ${classes.ok}`}>
            <IconCloudCheck size={16}/>
            {t`All check-ins synced`}
        </div>
    );
};
