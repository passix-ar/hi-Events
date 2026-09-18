import {ROSTER_REFRESH_MS} from "../../../../hooks/checkInRoster.logic";

// Three missed refresh cycles. One is noise — a single failed request, a slow page — three is a
// fault the door should hear about.
export const STALE_AFTER_MS = ROSTER_REFRESH_MS * 3;

export type SyncState =
    | { kind: 'error' }
    | { kind: 'offline'; pendingCount: number }
    | { kind: 'syncing'; pendingCount: number }
    | { kind: 'loading' }
    | { kind: 'stale'; loadedAt: number }
    | { kind: 'ok' };

export type SyncStateInput = {
    online: boolean;
    pendingCount: number;
    loadedAt: number | null;
    isLoading: boolean;
    loadError: boolean;
    // False until the component has mounted. The scanner route renders on the server too, where
    // there is no localStorage and `loadedAt` is always null; comparing against the clock before
    // mounting would give the server and the client different text and break hydration.
    mounted: boolean;
    now: number;
};

/**
 * Which of the six things the status bar can say. Split out from the component because the order is
 * the whole point and it is easy to get wrong: the dangerous case is not the one that looks broken,
 * it is the list quietly an hour out of date while the bar reads "all check-ins synced".
 *
 * `stale` sits fourth on purpose. If there are check-ins pending, the door already knows the network
 * is not keeping up; the case worth interrupting for is the other one — nothing pending, everything
 * apparently fine, and a roster from half an hour ago.
 */
export const pickSyncState = (
    {online, pendingCount, loadedAt, isLoading, loadError, mounted, now}: SyncStateInput,
): SyncState => {
    if (loadError && loadedAt === null) {
        return {kind: 'error'};
    }

    if (!online) {
        return {kind: 'offline', pendingCount};
    }

    if (pendingCount > 0) {
        return {kind: 'syncing', pendingCount};
    }

    if (isLoading && loadedAt === null) {
        return {kind: 'loading'};
    }

    // Keyed off how old the list actually is rather than off the last request failing, so it also
    // covers the refresh that never started (the check-in list never loaded, so the cycle is
    // disabled) and the one still stuck in flight.
    if (mounted && loadedAt !== null && now - loadedAt > STALE_AFTER_MS) {
        return {kind: 'stale', loadedAt};
    }

    return {kind: 'ok'};
};
